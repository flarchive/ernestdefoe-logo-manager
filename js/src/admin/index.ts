import app from 'flarum/admin/app';

// The settings themselves are declared through the JS Admin extender in
// ./extend — Flarum 2 removed the 1.x `app.extensionData` API, and calling it
// makes the extension fail to initialise rather than fail visibly.
app.initializers.add('ernestdefoe-logo-manager', () => {});

export { default as extend } from './extend';
