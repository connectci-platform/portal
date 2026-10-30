// Bootstrap 4 binds to the legacy jQuery (1.12.4) loaded in the header,
// before core's jQuery 4 replaces window.jQuery. Keep a reference to it so
// Bootstrap plugin calls and Bootstrap events use the same instance.
window.jQueryBootstrap = window.jQuery;
