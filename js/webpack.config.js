const path = require('path');
const webpack = require('webpack');
const config = require('flarum-webpack-config');

const base = config();

/**
 * F1 short imports → F2 module IDs verified against flarum/core 2.0.2
 * js/src/{common,forum}/compat.ts + proxifyCompat (strips common/|forum/).
 *
 * Forum-only: Search. Everything else used by the Live provider tree is common.
 */
const F1_TO_F2_ALIAS = {
  'flarum/extend': 'flarum/common/extend',
  'flarum/Model': 'flarum/common/Model',
  'flarum/Application': 'flarum/common/Application',
  'flarum/Component': 'flarum/common/Component',
  'flarum/models/User': 'flarum/common/models/User',
  'flarum/models/Group': 'flarum/common/models/Group',
  'flarum/components/Button': 'flarum/common/components/Button',
  'flarum/components/Dropdown': 'flarum/common/components/Dropdown',
  'flarum/components/Link': 'flarum/common/components/Link',
  'flarum/components/LoadingIndicator': 'flarum/common/components/LoadingIndicator',
  'flarum/components/Modal': 'flarum/common/components/Modal',
  'flarum/components/Separator': 'flarum/common/components/Separator',
  'flarum/components/Search': 'flarum/forum/components/Search',
  'flarum/components/HeaderSecondary': 'flarum/forum/components/HeaderSecondary',
  'flarum/components/IndexPage': 'flarum/forum/components/IndexPage',
  'flarum/helpers/avatar': 'flarum/common/helpers/avatar',
  'flarum/helpers/fullTime': 'flarum/common/helpers/fullTime',
  'flarum/helpers/highlight': 'flarum/common/helpers/highlight',
  'flarum/helpers/icon': 'flarum/common/helpers/icon',
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

const aliasRewrite = new webpack.NormalModuleReplacementPlugin(/^flarum\//, (resource) => {
  if (Object.prototype.hasOwnProperty.call(F1_TO_F2_ALIAS, resource.request)) {
    resource.request = F1_TO_F2_ALIAS[resource.request];
  }
});

module.exports = {
  ...base,
  entry: {
    ...base.entry,
    'forum-f2': path.resolve(__dirname, 'forum-f2.js'),
  },
  plugins: [...(base.plugins || []), aliasRewrite],
  resolve: {
    ...base.resolve,
    alias: {
      ...(base.resolve && base.resolve.alias),
      ...F1_TO_F2_ALIAS,
    },
  },
};
