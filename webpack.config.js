const path = require('path');
const { VueLoaderPlugin } = require('vue-loader');
const webpack = require('webpack');

const isDev = process.env.NODE_ENV === 'development';

module.exports = {
  entry: {
    main: './src/main.js',
    editor: './src/editor.js',
    results: './src/results.js',
    public: './src/public.js',
    admin: './src/admin.js',
    files: './src/files.js',
  },
  output: {
    filename: 'formvox-[name].js',
    chunkFilename: 'formvox-[name].js',
    path: path.resolve(__dirname, 'js'),
    clean: true,
  },
  optimization: {
    splitChunks: false,
  },
  module: {
    rules: [
      {
        test: /\.vue$/,
        loader: 'vue-loader',
      },
      {
        test: /\.css$/,
        use: ['style-loader', 'css-loader'],
      },
      {
        test: /\.scss$/,
        use: ['style-loader', 'css-loader', 'sass-loader'],
      },
      {
        test: /\.js$/,
        loader: 'string-replace-loader',
        include: path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
        options: {
          multiple: [
            {
              search: '"appName"',
              replace: '"formvox"',
              flags: 'g',
            },
            {
              search: '"appVersion"',
              replace: '"0.2.6"',
              flags: 'g',
            },
          ],
        },
      },
    ],
  },
  plugins: [
    new VueLoaderPlugin(),
    new webpack.DefinePlugin({
      __VUE_OPTIONS_API__: JSON.stringify(true),
      __VUE_PROD_DEVTOOLS__: JSON.stringify(isDev),
      __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(isDev),
      appName: JSON.stringify('formvox'),
    }),
  ],
  resolve: {
    extensions: ['.js', '.vue'],
    alias: {
      '@': path.resolve(__dirname, 'src'),
      'vue': 'vue/dist/vue.runtime.esm-bundler.js',
    },
    fallback: {
      'path': false,
      'fs': false,
      'crypto': false,
      'stream': false,
      'os': false,
      'http': false,
      'https': false,
      'zlib': false,
      'string_decoder': false,
      'buffer': false,
      'util': false,
    },
  },
  mode: isDev ? 'development' : 'production',
  devtool: isDev ? 'source-map' : false,
};
