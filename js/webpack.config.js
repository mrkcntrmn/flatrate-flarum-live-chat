const path = require('path');
const config = require('flarum-webpack-config');

const base = config();

/**
 * F1 short imports → F2 registry IDs (flarum/core 2.0.2).
 *
 * flarum-webpack-config v3 resolves `flarum/*` via externals before
 * resolve.alias runs, so short F1 paths must be rewritten here or
 * `flarum.reg.get('core', 'Model')` (etc.) returns undefined and
 * `class extends undefined` blows up the Live forum-f2 boot.
 *
 * Forum-only: Search, HeaderSecondary, IndexPage. Everything else used by
 * the Live provider tree is under common/.
 */
const F1_TO_F2_REQUEST = {
  'flarum/extend': 'flarum/common/extend',
  'flarum/app': 'flarum/forum/app',
  'flarum/Model': 'flarum/common/Model',
  'flarum/Application': 'flarum/common/Application',
  'flarum/Component': 'flarum/common/Component',
  'flarum/models/User': 'flarum/common/models/User',
  'flarum/models/Group': 'flarum/common/models/Group',
  'flarum/components/Button': 'flarum/common/components/Button',
  'flarum/components/Dropdown': 'flarum/common/components/Dropdown',
  'flarum/components/Link': 'flarum/common/components/Link',
  'flarum/components/LinkButton': 'flarum/common/components/LinkButton',
  'flarum/components/LoadingIndicator': 'flarum/common/components/LoadingIndicator',
  'flarum/components/Modal': 'flarum/common/components/Modal',
  'flarum/components/Separator': 'flarum/common/components/Separator',
  'flarum/components/Search': 'flarum/forum/components/Search',
  'flarum/components/HeaderSecondary': 'flarum/forum/components/HeaderSecondary',
  'flarum/components/IndexPage': 'flarum/forum/components/IndexPage',
  // F2 removed helpers/avatar; Avatar is a common component.
  'flarum/helpers/avatar': 'flarum/common/components/Avatar',
  'flarum/components/Avatar': 'flarum/common/components/Avatar',
  'flarum/helpers/fullTime': 'flarum/common/helpers/fullTime',
  'flarum/helpers/highlight': 'flarum/common/helpers/highlight',
  // F2 removed helpers/icon; Icon is a common component.
  'flarum/helpers/icon': 'flarum/common/components/Icon',
  'flarum/components/Icon': 'flarum/common/components/Icon',
  'flarum/helpers/listItems': 'flarum/common/helpers/listItems',
  'flarum/helpers/username': 'flarum/common/helpers/username',
  'flarum/utils/classList': 'flarum/common/utils/classList',
  'flarum/utils/computed': 'flarum/common/utils/computed',
  'flarum/utils/extractText': 'flarum/common/utils/extractText',
  'flarum/utils/humanTime': 'flarum/common/utils/humanTime',
  'flarum/utils/ItemList': 'flarum/common/utils/ItemList',
  'flarum/utils/Stream': 'flarum/common/utils/Stream',
  'flarum/utils/SubtreeRetainer': 'flarum/common/utils/SubtreeRetainer',
  'flarum/utils/throttleDebounce': 'flarum/common/utils/throttleDebounce',
};

function remapFlarumRequest(request) {
  return Object.prototype.hasOwnProperty.call(F1_TO_F2_REQUEST, request)
    ? F1_TO_F2_REQUEST[request]
    : request;
}

function flarumRegistryExternal({ request }, callback) {
  let matches;
  if ((matches = /^flarum\/(.+)$/.exec(remapFlarumRequest(request)))) {
    return callback(null, `root flarum.reg.get('core', '${matches[1]}')`);
  }
  if ((matches = /^ext:([^/]+)\/(?:flarum-(?:ext-)?)?([^/]+)(?:\/(.+))?$/.exec(request))) {
    const namespace = `${matches[1]}-${matches[2]}`;
    const id = matches[3];
    return callback(null, `root flarum.reg.get('${namespace}', '${id}')`);
  }
  return callback();
}

module.exports = {
  ...base,
  entry: {
    ...base.entry,
    'forum-f2': path.resolve(__dirname, 'forum-f2.js'),
  },
  externals: [
    {
      jquery: 'jQuery',
    },
    flarumRegistryExternal,
  ],
  resolve: {
    ...base.resolve,
    alias: {
      ...(base.resolve && base.resolve.alias),
      ...F1_TO_F2_REQUEST,
    },
  },
};
