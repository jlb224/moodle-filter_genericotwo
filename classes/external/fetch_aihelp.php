<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External function for fetching AI help.
 *
 * @package    filter_genericotwo
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_genericotwo\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;

defined('MOODLE_INTERNAL') || die();

class fetch_aihelp extends external_api {

    /**
     * Parameters for the execute function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'prompt' => new external_value(PARAM_RAW, 'The prompt/instructions from the user', VALUE_REQUIRED),
            'currentcode' => new external_value(PARAM_RAW, 'JSON encoded string containing all editor contents', VALUE_REQUIRED),
        ]);
    }

    /**
     * Execute the external function.
     *
     * @param string $prompt The user prompt.
     * @param string $currentcode The JSON encoded editor contents.
     * @return array Result containing status and JSON encoded responses.
     */
    public static function execute($prompt, $currentcode) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'prompt' => $prompt,
            'currentcode' => $currentcode,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('filter/genericotwo:managetemplates', $context);

        // Decode the incoming JSON payload.
        /*
        $editors = json_decode($params['currentcode'], true);
        $responsedata = [];

        if (is_array($editors)) {
            foreach ($editors as $id => $content) {
                // For now, just return "hello world" for each editor.
                $responsedata[$id] = "hello world";
            }
        }
        */

        // Build the full prompt
        $thefullprompt = self::fetch_full_prompt($params['prompt'], $params['currentcode']);

        global $USER;
        $action = new \core_ai\aiactions\generate_text(
            contextid: $context->id,
            userid: $USER->id,
            prompttext: $thefullprompt
        );
        $manager = \core\di::get(\core_ai\manager::class);
        $llmresponse = $manager->process_action($action);
        $responsedata = $llmresponse->get_response_data();

        if (
            is_null($responsedata) ||
            !is_array($responsedata) ||
            !array_key_exists('generatedcontent', $responsedata) ||
            is_null($responsedata['generatedcontent'])
        ) {
            return [
                'status' => false,
                'response' => '',
                'message' => 'Failed to get valid response from AI provider.',
            ];
        }

        $generatedcontent = $responsedata['generatedcontent'];

        $airesponse = self::decode_ai_json($generatedcontent);

        if ($airesponse === null) {
            // The most common cause of unparseable output is the model hitting its output token
            // limit part way through the JSON, so report that case specifically.
            $finishreason = strtolower((string)($responsedata['finishreason'] ?? ''));
            if (in_array($finishreason, ['length', 'max_tokens', 'maxtokens'], true)) {
                return [
                    'status' => false,
                    'response' => '',
                    'message' => get_string('airesponsetruncated', 'filter_genericotwo'),
                ];
            }
            return [
                'status' => false,
                'response' => '',
                'message' => get_string('jsonparsefail', 'filter_genericotwo') . ' ' .
                    get_string('jsonparsefaildetail', 'filter_genericotwo', (object)[
                        'error' => json_last_error_msg(),
                        'snippet' => shorten_text(trim($generatedcontent), 300),
                    ]),
            ];
        }

        return [
            'status' => true,
            'response' => json_encode($airesponse->editors),
            'message' => $airesponse->description ?? get_string('aigensuccess', 'filter_genericotwo'),
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'True if successful'),
            'response' => new external_value(PARAM_RAW, 'JSON string containing new contents for editors'),
            'message' => new external_value(PARAM_RAW, 'Error or success message', VALUE_OPTIONAL),
            'provider' => new external_value(PARAM_TEXT, 'The AI provider used', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Decode the JSON object out of the raw AI output.
     *
     * Models routinely wrap the JSON in markdown fences or in explanatory prose, and often emit
     * raw newlines inside JSON strings when the string values are multi-line code, so a plain
     * json_decode() of the whole payload is not reliable.
     *
     * @param string $content The raw generated content.
     * @return \stdClass|null The decoded object, or null if nothing usable could be parsed.
     */
    private static function decode_ai_json($content) {
        $content = trim((string)$content);

        // Prefer the contents of a fenced code block if there is one.
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $content, $matches)) {
            $content = trim($matches[1]);
        }

        // Try the whole payload first, then each balanced {...} object found within it. The first
        // brace is not always the start of the JSON (prose may mention {{AUTOID}} for example).
        if (($decoded = self::try_decode($content)) !== null) {
            return $decoded;
        }

        $offset = 0;
        $attempts = 0;
        while ($attempts < 10 && ($pos = strpos($content, '{', $offset)) !== false) {
            $attempts++;
            $offset = $pos + 1;
            $chunk = self::extract_balanced_object($content, $pos);
            if ($chunk === null) {
                continue;
            }
            if (($decoded = self::try_decode($chunk)) !== null) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Attempt to decode a candidate JSON string into the expected response object.
     *
     * @param string $candidate The candidate JSON.
     * @return \stdClass|null The decoded object, or null if it is not valid or not the shape we want.
     */
    private static function try_decode($candidate) {
        foreach ([$candidate, self::escape_control_chars_in_strings($candidate)] as $attempt) {
            $decoded = json_decode($attempt);
            if ($decoded instanceof \stdClass && isset($decoded->editors)) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Return the balanced {...} substring starting at the given offset, ignoring braces that
     * appear inside JSON string literals.
     *
     * @param string $content The string to scan.
     * @param int $start Offset of the opening brace.
     * @return string|null The balanced substring, or null if the braces never balance.
     */
    private static function extract_balanced_object($content, $start) {
        $depth = 0;
        $instring = false;
        $escaped = false;
        $len = strlen($content);

        for ($i = $start; $i < $len; $i++) {
            $char = $content[$i];

            if ($instring) {
                if ($escaped) {
                    $escaped = false;
                } else if ($char === '\\') {
                    $escaped = true;
                } else if ($char === '"') {
                    $instring = false;
                }
                continue;
            }

            if ($char === '"') {
                $instring = true;
            } else if ($char === '{') {
                $depth++;
            } else if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Escape raw control characters that appear inside JSON string literals.
     *
     * Models frequently emit literal newlines and tabs inside JSON strings when those strings hold
     * multi-line code, which is invalid JSON. This repairs that without touching anything else.
     *
     * @param string $json The candidate JSON.
     * @return string The repaired JSON.
     */
    private static function escape_control_chars_in_strings($json) {
        $out = '';
        $instring = false;
        $escaped = false;
        $len = strlen($json);

        for ($i = 0; $i < $len; $i++) {
            $char = $json[$i];

            if (!$instring) {
                if ($char === '"') {
                    $instring = true;
                }
                $out .= $char;
                continue;
            }

            if ($escaped) {
                $out .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $out .= $char;
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $instring = false;
                $out .= $char;
                continue;
            }

            if ($char === "\n") {
                $out .= '\\n';
            } else if ($char === "\r") {
                $out .= '\\r';
            } else if ($char === "\t") {
                $out .= '\\t';
            } else if (ord($char) < 0x20) {
                $out .= sprintf('\\u%04x', ord($char));
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    private static function fetch_full_prompt($prompt, $currentcode) {
        // Build a prompt using the template below;
        $promptbits = [];
        $promptbits[] = "You are an expert front end developer for Moodle (a learning management system).";
        $promptbits[] = "You developing a front end widget using Moodle's Generic Two widget authoring system.";
        $promptbits[] = "The widget edit page contains 5 code editing areas.";
        $promptbits[] = "For HTML and JS code, Generico Two uses parameter placeholders of the format \{\{{parametername}\}\}. \n For SQL dataset parameters use ? placeholders.";
        $vars = 'The following variables are resolved at runtime and are the ONLY built in variables available. ';
        $vars .= 'Do not invent others. Any other {{name}} placeholder must be declared by the widget author in the ';
        $vars .= 'variable defaults field, and is then supplied in the filter tag at runtime.' . PHP_EOL;
        $vars .= \filter_genericotwo\variables::fetch_prompt_summary() . PHP_EOL;
        $vars .= 'Mustache HTML-escapes {{name}}, so inside JavaScript string literals use the ';
        $vars .= 'unescaped form {{{name}}} instead.';
        $promptbits[] = $vars;
        $promptbits[] = "The five coding areas are:";
        $promptbits[] = "'id_content'. This is the main content area, which contains html and mustache. (field label: Body)";
        $tend = "'id_templateend'. This is an optional content area which also contains html and mustache. It is used when the user at runtime may place content between this code and the code from id_content. ";
        $tend .= "e.g for an audio player widget, the user may place a media link between the id_content code and the id_templateend code at runtime. (field label: Template End)";
        $promptbits[] = $tend;
        $examplejs = "require(['core/log'],
    function(log) {
        document.getElementById('{{AUTOID}}_greetingbox').textContent = 'hello';
    }
);";
        $promptbits[] = "'id_jscontent'. This contains javascript, probably but not always, the definition of an AMD module. It will usually perform some action on the html/mustache content. (field label: JS Content). An example script is: " . PHP_EOL . $examplejs;
        $promptbits[] = "'id_dataset'. This contains SQL that may have ?parameters that will be replaced by user input values at runtime. (field label: Dataset Body)";
        $promptbits[] = "'id_customcss'. This is the custom css area. CSS declared is injected onto the page at runtime during page load. Generico mustache and js variables are not available in the custom css area. So do not use them in custom css. (field label: Custom CSS)";
        $promptbits[] = "The current editor content is:" . PHP_EOL . $currentcode;
        $promptbits[] = "You should follow the instructions below to add/edit editor content.";
        $shape = "Your response should be a JSON object with two keys: 'editors' (an object keyed by editor id, holding the ";
        $shape .= "complete replacement content for that editor) and 'description' (a short text description of the changes you made).";
        $promptbits[] = $shape;
        $onlychanged = "IMPORTANT: only include an editor in the 'editors' object if its content actually needs to change. ";
        $onlychanged .= "Omit every editor you are leaving untouched entirely - do not echo back unchanged content. ";
        $onlychanged .= "This keeps your response short enough that it will not be truncated.";
        $promptbits[] = $onlychanged;
        $rawjson = "IMPORTANT: return raw JSON only, with no markdown code fences and no text outside the JSON object. ";
        $rawjson .= "All newlines, tabs and quotes inside the editor content strings must be properly escaped ";
        $rawjson .= "(\\n, \\t, \\\") so that the response is valid JSON.";
        $promptbits[] = $rawjson;
        $promptbits[] = "Keep the 'description' brief - a few sentences at most.";
        $promptbits[] = "Your instructions for this task are:" . PHP_EOL . $prompt;
        return implode(PHP_EOL, $promptbits);
    }

}
