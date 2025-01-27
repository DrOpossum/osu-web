// karma-webpack doesn't exit on compile error.
// This plugin makes it exit on compile error.
class ExitOnErrorWebpackPlugin {
  apply(compiler) {
    compiler.hooks.done.tap('ExitOnErrorWebpackPlugin', (stats) => {
      if (stats && stats.hasErrors()) {
        stats.toJson().errors.forEach((error) => {
          console.error(error);
        });
        process.exit(1);
      }
    });
  }
}

const webpackConfig = require('./webpack.config.js');
webpackConfig['plugins'].push(new ExitOnErrorWebpackPlugin());
webpackConfig['mode'] = 'development';
webpackConfig['devtool'] = 'inline-source-map';
delete webpackConfig.optimization; // karma doesn't work with splitChunks...or runtimeChunk
delete webpackConfig.entry; // test runner doesn't use the entry points

const testIndex = './tests/karma/index.ts';

const files = [
  './public/js/vendor.js',
  './public/js/app-deps.js',
  './public/js/locales/en.js',
  './tests/karma/globals.js', // shims for tests
  testIndex,
];

const preprocessors = {};
preprocessors[testIndex] = ['webpack', 'sourcemap'];

module.exports = function (config) {
  config.set({
    autoWatch: true,
    basePath: '.',
    browsers: ['ChromeHeadless'],
    colors: true,
    concurrency: Infinity,
    exclude: [],
    frameworks: ['jasmine'],
    files: files,
    logLevel: config.LOG_INFO,
    mime: { 'text/x-typescript': ['ts', 'tsx'] },
    port: 9876,
    preprocessors: preprocessors,
    reporters: ['progress'],
    singleRun: false, // set to true for the process to exit after completing.
    webpack: webpackConfig,
    webpackMiddleware: {
      noInfo: true,
      stats: 'errors-only'
    },
  });
};
