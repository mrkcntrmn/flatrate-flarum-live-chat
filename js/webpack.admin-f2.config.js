const path = require('path');
const config = require('flarum-webpack-config');

const base = config();
const adminImports = {
  'flarum/app': 'flarum/admin/app',
  'flarum/Component': 'flarum/common/Component',
  'flarum/components/Button': 'flarum/common/components/Button',
  'flarum/components/ExtensionPage': 'flarum/admin/components/ExtensionPage',
};

// Keep F2 admin registry IDs separate from the existing forum and F1 builds.
module.exports = {
  ...base,
  entry: { 'admin-f2': path.resolve(__dirname, 'admin-f2.js') },
  output: { ...base.output, clean: false },
  externals: [
    function ({ request }, callback) {
      const target = adminImports[request] || request;
      const match = /^flarum\/(.+)$/.exec(target);
      if (match) return callback(null, `root flarum.reg.get('core', '${match[1]}')`);
      return callback();
    },
  ],
};
