// CSS-Editor für metaRechnung (CodeMirror 6). Ersetzt eine <textarea data-css-editor> durch einen Editor; ohne JS bleibt die Textarea nutzbar.
import { EditorState } from '@codemirror/state';
import { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter, drawSelection } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { css } from '@codemirror/lang-css';
import { syntaxHighlighting, HighlightStyle, bracketMatching, indentOnInput } from '@codemirror/language';
import { tags as t } from '@lezer/highlight';

const hl = HighlightStyle.define([
  { tag: [t.propertyName, t.attributeName], color: 'var(--cm-prop, #0b6bcb)' },
  { tag: [t.string, t.number, t.color, t.unit, t.keyword], color: 'var(--cm-val, #b4410a)' },
  { tag: [t.tagName, t.className, t.labelName, t.atom, t.definitionKeyword], color: 'var(--cm-sel, #7c3aed)' },
  { tag: [t.comment], color: 'var(--cm-com, #5b6478)', fontStyle: 'italic' },
  { tag: [t.variableName], color: 'var(--cm-var, #0f766e)' },
]);

window.metaCssEditor = function (ta, onChange) {
  const host = document.createElement('div');
  ta.parentNode.insertBefore(host, ta.nextSibling);   // Editor direkt hinter der Textarea (Tab-Reihenfolge!)
  const view = new EditorView({
    parent: host,
    doc: ta.value,
    extensions: [
      lineNumbers(), highlightActiveLineGutter(), highlightActiveLine(), drawSelection(), history(), indentOnInput(), bracketMatching(),
      css(), syntaxHighlighting(hl), EditorView.lineWrapping,
      // Tab bleibt absichtlich unbelegt: Tastaturnutzer dürfen den Editor mit Tab verlassen (WCAG 2.1.2)
      keymap.of([...defaultKeymap, ...historyKeymap]),
      EditorView.contentAttributes.of({ 'aria-label': ta.getAttribute('aria-label') || 'CSS-Editor', 'aria-multiline': 'true', spellcheck: 'false', autocapitalize: 'off' }),
      EditorView.updateListener.of((u) => { if (u.docChanged) { ta.value = u.state.doc.toString(); if (onChange) onChange(ta.value); } }),
    ],
  });
  ta.style.display = 'none';
  return { view, set(v) { view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: v } }); }, focus() { view.focus(); } };
};
