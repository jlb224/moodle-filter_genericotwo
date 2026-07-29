// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Insert-variable picker for the template editor.
 *
 * Attaches a searchable dropdown to a CodeMirror editor or a plain form field, which inserts a
 * template variable at the cursor. The variable catalogue is built in PHP by
 * \filter_genericotwo\variables so the picker, the AI helper and the docs can not drift apart.
 *
 * @module     filter_genericotwo/variables
 * @copyright  2025 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["core/str"], function (Str) {
    // Token in a snippet that is replaced by the author's current selection.
    var SELTOKEN = "%%SEL%%";

    var strings = null;

    /**
     * Fetch (once) the strings the picker UI needs.
     *
     * @returns {Promise} Resolves to an object of strings keyed by identifier.
     */
    function fetchStrings() {
        if (!strings) {
            strings = Str.get_strings([
                { key: "variables_insert", component: "filter_genericotwo" },
                { key: "variables_search", component: "filter_genericotwo" },
                { key: "variables_nomatches", component: "filter_genericotwo" },
                { key: "variables_jsnote", component: "filter_genericotwo" },
            ]).then(function (fetched) {
                return {
                    insert: fetched[0],
                    search: fetched[1],
                    nomatches: fetched[2],
                    jsnote: fetched[3],
                };
            });
        }
        return strings;
    }

    /**
     * Parse the variable defaults field into the template's own variable names.
     *
     * The field holds a filter string body, e.g. "width=500,volume=0.4|0.5", and matches how
     * utils::fetch_filter_properties() reads it: name=value pairs, comma separated.
     *
     * @param {string} defaults The raw variable defaults field value.
     * @returns {Array} Items for the "this template" group.
     */
    function parseTemplateVariables(defaults) {
        var items = [];
        var seen = {};
        var pattern = /([^=,]+)=("[^"]*"|[^,"]*)/g;
        var match;

        while ((match = pattern.exec(defaults || "")) !== null) {
            var name = match[1].trim();
            // Skip anything that is not a plain variable name, and anything already listed
            // in one of the built in groups.
            if (!name || seen[name] || !/^[A-Za-z0-9_-]+$/.test(name)) {
                continue;
            }
            seen[name] = true;
            // Option lists collapse to the first option at render time, so show that as the value.
            var value = match[2].replace(/^"|"$/g, "").split("|")[0];
            items.push({ label: name, name: name, description: value });
        }

        return items;
    }

    /**
     * Insert text into a CodeMirror editor at the cursor, replacing any selection.
     *
     * @param {Object} view The CodeMirror EditorView.
     * @param {string} text The text to insert.
     * @param {string} placeholder Substring of text to leave selected, if any.
     */
    function insertIntoView(view, text, placeholder) {
        var from = view.state.selection.main.from;
        view.dispatch(view.state.replaceSelection(text));

        var offset = placeholder ? text.indexOf(placeholder) : -1;
        if (offset >= 0) {
            view.dispatch({
                selection: {
                    anchor: from + offset,
                    head: from + offset + placeholder.length,
                },
            });
        }
        view.focus();
    }

    /**
     * Insert text into a plain input or textarea at the cursor, replacing any selection.
     *
     * @param {HTMLElement} el The field.
     * @param {string} text The text to insert.
     * @param {string} placeholder Substring of text to leave selected, if any.
     */
    function insertIntoField(el, text, placeholder) {
        var start = el.selectionStart === null ? el.value.length : el.selectionStart;
        var end = el.selectionEnd === null ? start : el.selectionEnd;

        el.value = el.value.slice(0, start) + text + el.value.slice(end);

        var offset = placeholder ? text.indexOf(placeholder) : -1;
        if (offset >= 0) {
            el.setSelectionRange(start + offset, start + offset + placeholder.length);
        } else {
            el.setSelectionRange(start + text.length, start + text.length);
        }

        el.focus();
        // Let anything listening (the live preview, presets) know the value changed.
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("change", { bubbles: true }));
    }

    /**
     * Work out the text to insert for an item, given the target and current selection.
     *
     * @param {Object} item The catalogue item.
     * @param {boolean} triplestache Whether to insert {{{name}}} rather than {{name}}.
     * @param {string} selection The author's currently selected text.
     * @returns {string} The text to insert.
     */
    function buildInsertText(item, triplestache, selection) {
        if (item.snippet) {
            return item.snippet.split(SELTOKEN).join(selection || "");
        }
        return triplestache ? "{{{" + item.name + "}}}" : "{{" + item.name + "}}";
    }

    /**
     * Build one menu entry.
     *
     * @param {Object} item The catalogue item.
     * @param {Function} onpick Called with the item when the entry is chosen.
     * @returns {HTMLElement} The entry.
     */
    function buildMenuItem(item, onpick) {
        var button = document.createElement("button");
        button.type = "button";
        button.className = "dropdown-item filter_genericotwo_varitem";

        var label = document.createElement("span");
        label.className = "filter_genericotwo_varitem_label";
        label.textContent = item.label;
        button.appendChild(label);

        if (item.description) {
            var description = document.createElement("small");
            description.className = "d-block text-muted";
            description.textContent = item.description;
            button.appendChild(description);
        }

        // Search over both label and description, so "email" finds a profile field named Email.
        button.dataset.search = (item.label + " " + (item.description || "")).toLowerCase();

        button.addEventListener("click", function (e) {
            e.preventDefault();
            onpick(item);
        });

        return button;
    }

    /**
     * Attach an insert-variable picker to a target.
     *
     * @param {HTMLElement} toolbar Element to append the picker button to.
     * @param {Array} groups The variable catalogue from PHP.
     * @param {Object} target Where to insert. Either {view: EditorView} or {field: HTMLElement}.
     * @param {boolean} triplestache Whether to insert unescaped {{{name}}} variables.
     */
    function attach(toolbar, groups, target, triplestache) {
        fetchStrings()
            .then(function (s) {
                var wrapper = document.createElement("div");
                wrapper.className = "filter_genericotwo_varpicker";

                var button = document.createElement("button");
                button.type = "button";
                button.className = "btn btn-secondary btn-sm dropdown-toggle";
                button.innerHTML = '<i class="fa fa-code" aria-hidden="true"></i> ';
                button.appendChild(document.createTextNode(s.insert));
                button.setAttribute("aria-expanded", "false");
                button.setAttribute("aria-haspopup", "true");
                wrapper.appendChild(button);

                var menu = document.createElement("div");
                menu.className = "filter_genericotwo_varmenu";
                wrapper.appendChild(menu);

                var searchbox = document.createElement("input");
                searchbox.type = "text";
                searchbox.className = "form-control form-control-sm";
                searchbox.placeholder = s.search;
                searchbox.setAttribute("aria-label", s.search);
                var searchwrap = document.createElement("div");
                searchwrap.className = "filter_genericotwo_varsearch";
                searchwrap.appendChild(searchbox);
                menu.appendChild(searchwrap);

                var list = document.createElement("div");
                list.className = "filter_genericotwo_varlist";
                menu.appendChild(list);

                var nomatches = document.createElement("div");
                nomatches.className = "text-muted small px-3 py-2 d-none";
                nomatches.textContent = s.nomatches;
                menu.appendChild(nomatches);

                if (triplestache) {
                    var note = document.createElement("div");
                    note.className = "filter_genericotwo_varnote text-muted small";
                    note.textContent = s.jsnote;
                    menu.appendChild(note);
                }

                var close = function () {
                    menu.classList.remove("show");
                    button.setAttribute("aria-expanded", "false");
                };

                var pick = function (item) {
                    var selection = "";
                    if (target.view) {
                        var range = target.view.state.selection.main;
                        selection = target.view.state.doc.sliceString(range.from, range.to);
                    } else {
                        selection = target.field.value.slice(
                            target.field.selectionStart,
                            target.field.selectionEnd,
                        );
                    }

                    var text = buildInsertText(item, triplestache, selection);
                    // A snippet that consumed the selection has already placed it, so only
                    // hunt for a placeholder when we did not.
                    var placeholder = item.placeholder;

                    close();

                    if (target.view) {
                        insertIntoView(target.view, text, placeholder);
                    } else {
                        insertIntoField(target.field, text, placeholder);
                    }
                };

                // Redrawn on open, because the template's own variables change as the author types.
                var render = function () {
                    list.innerHTML = "";
                    groups.forEach(function (group) {
                        var items = group.items;
                        if (group.key === "template") {
                            items = parseTemplateVariables(
                                (document.getElementById("id_variabledefaults") || {}).value,
                            );
                        }
                        if (!items || !items.length) {
                            return;
                        }

                        var header = document.createElement("h6");
                        header.className = "dropdown-header";
                        header.textContent = group.label;
                        list.appendChild(header);

                        items.forEach(function (item) {
                            list.appendChild(buildMenuItem(item, pick));
                        });
                    });
                };

                var applyFilter = function () {
                    var term = searchbox.value.trim().toLowerCase();
                    var anyvisible = false;

                    // Headers hide when every item under them is filtered out.
                    var pending = null;
                    var pendingvisible = false;
                    Array.prototype.forEach.call(list.children, function (child) {
                        if (child.tagName === "H6") {
                            if (pending) {
                                pending.classList.toggle("d-none", !pendingvisible);
                            }
                            pending = child;
                            pendingvisible = false;
                            return;
                        }
                        var match = !term || child.dataset.search.indexOf(term) !== -1;
                        child.classList.toggle("d-none", !match);
                        if (match) {
                            pendingvisible = true;
                            anyvisible = true;
                        }
                    });
                    if (pending) {
                        pending.classList.toggle("d-none", !pendingvisible);
                    }

                    nomatches.classList.toggle("d-none", anyvisible);
                };

                button.addEventListener("click", function (e) {
                    e.preventDefault();
                    if (menu.classList.contains("show")) {
                        close();
                        return;
                    }
                    render();
                    searchbox.value = "";
                    applyFilter();
                    menu.classList.add("show");
                    button.setAttribute("aria-expanded", "true");
                    searchbox.focus();
                });

                searchbox.addEventListener("input", applyFilter);

                menu.addEventListener("keydown", function (e) {
                    if (e.key === "Escape") {
                        close();
                        button.focus();
                    }
                });

                // Close when the author clicks anywhere else on the page.
                document.addEventListener("click", function (e) {
                    if (!wrapper.contains(e.target)) {
                        close();
                    }
                });

                toolbar.appendChild(wrapper);
                return s;
            })
            .catch(function (e) {
                window.console.error(e);
            });
    }

    return {
        attach: attach,
    };
});
