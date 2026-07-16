(function (global) {
  'use strict';

  if (!global) {
    return;
  }

  if (typeof global.global === 'undefined') {
    global.global = global;
  }

  if (typeof global.process === 'undefined') {
    global.process = {
      env: {},
      browser: true,
      version: '',
      versions: {}
    };
  }
})(typeof window !== 'undefined' ? window : this);
