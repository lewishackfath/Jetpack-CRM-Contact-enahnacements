// Event-level regression tests for the production script, using a minimal DOM boundary.
// Run with Node.js; no dependencies or browser/network side effects.
const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const assert = require('node:assert/strict');
class Element {
  constructor() { this.listeners = {}; this.dataset = {}; this.attributes = {}; this.classes = new Set(); this.classList = {add: name => this.classes.add(name), remove: name => this.classes.delete(name)}; }
  addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); }
  dispatch(name, props = {}) {
    const event = {defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...props};
    for (const listener of this.listeners[name] || []) listener(event);
    return event;
  }
  setAttribute(name, value) { this.attributes[name] = value; }
  removeAttribute(name) { delete this.attributes[name]; }
}
const file = new Element();
file.files = [];
Object.defineProperty(file, 'value', {set(value) { if (value === '') this.files = []; }});
file.setCustomValidity = message => { file.validationMessage = message; };
file.focus = () => {};
const status = {}, error = {}, zone = new Element();
zone.querySelector = selector => selector === 'input[type="file"]' ? file : selector.includes('status') ? status : error;
zone.append = child => { zone.clear = child; };
const save = {tagName: 'INPUT', value: 'Save course record', disabled: false};
const form = new Element();
form.querySelector = () => ({value: 'jpcc_save_record'});
form.querySelectorAll = () => [save];
const window = new Element(); window.location = {search: ''};
const document = {
  querySelectorAll: selector => selector === '[data-jpcc-dropzone]' ? [zone] : selector === '.jpcc-form' ? [form] : [],
  querySelector: () => null,
  createElement: () => new Element(),
};
const jpccAdmin = {noFile:'No file selected.', fileError:'Invalid file.', dropUnavailable:'Use Choose file.', saving:'Saving…', clearFile:'Clear selection'};
runInNewContext(readFileSync(require('node:path').join(__dirname, '../assets/admin.js'), 'utf8'), {document, window, jpccAdmin, URLSearchParams});
let checks = 0;
function expect(condition, message) { assert.ok(condition, message); checks++; console.log('PASS: ' + message); }
const pdf = {name:'first-aid.pdf', size:1024};
zone.dispatch('dragenter');
expect(zone.classes.has('jpcc-dragover'), 'Dragging into the upload zone highlights it');
const drop = zone.dispatch('drop', {dataTransfer:{files:[pdf]}});
expect(drop.defaultPrevented && file.files[0] === pdf, 'Dropping a certificate populates the actual file input and prevents navigation');
expect(status.textContent.includes('first-aid.pdf') && status.textContent.includes('1 KB') && !file.validationMessage, 'Selected filename and size are announced');
expect(!zone.classes.has('jpcc-dragover'), 'Drop removes the drag highlight');
zone.clear.dispatch('click');
expect(file.files.length === 0 && status.textContent === jpccAdmin.noFile && !file.validationMessage, 'Clear selection resets the upload and validation');
for (const files of [[{name:'script.php', size:100}], [{name:'empty.pdf', size:0}], [{name:'large.pdf', size:5242881}], [pdf,pdf]]) {
  zone.dispatch('drop', {dataTransfer:{files}});
  expect(file.files.length === 0 && file.validationMessage === jpccAdmin.fileError && error.textContent === jpccAdmin.fileError, 'Unsupported, empty, oversized or multiple dropped files are rejected');
}
file.files = [{name:'certificate.PNG', size:500}]; file.dispatch('change');
expect(!file.validationMessage && status.textContent.includes('certificate.PNG'), 'Choosing a valid replacement clears an earlier upload error');
const first = form.dispatch('submit');
expect(!first.defaultPrevented && save.disabled && save.value === jpccAdmin.saving, 'First valid submission disables Save and shows progress');
expect(form.dispatch('submit').defaultPrevented, 'Further submissions are blocked while saving');
window.dispatch('pageshow');
expect(!save.disabled && save.value === 'Save course record' && !form.dataset.submitting, 'Returning with the browser Back button restores the form controls');
console.log(`\n${checks} browser-event checks passed.`);
