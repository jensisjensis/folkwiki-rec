const fs = require('fs');
const packageJson = fs.readFileSync('./package.json');
const version = JSON.parse(packageJson).version || '';
const webpack = require('webpack');

module.exports = {
    publicPath: '/',
    transpileDependencies: ['vuetify'],
    configureWebpack: {
        plugins: [
            // This is just to pull the version from package.json into ffConfig.js
            new webpack.DefinePlugin({
                'process.env': {
                    PACKAGE_VERSION: '"' + version + '"',
                },
            }),
        ],
        experiments: {
            asyncWebAssembly: true,
        }
    },
chainWebpack: (config) => {
    // Benenne die im Build erzeugte index.html in mic.html um.
    config.plugin('html').tap((args) => {
        args[0].filename = 'mic.html';
        return args;
    });

    // PWA-Plugin entfernen
    config.plugins.delete('pwa');
    },
    pwa: {
        name: 'Eso qué ye?',
        theme_color: '#055581',
        background_color: '#055581',
    },
};