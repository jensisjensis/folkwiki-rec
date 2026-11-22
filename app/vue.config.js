const fs = require('fs');
const webpack = require('webpack');

const packageJson = JSON.parse(fs.readFileSync('./package.json', 'utf8'));
const version = packageJson.version || '';

module.exports = {
    publicPath: process.env.NODE_ENV === 'production'
        ? '/folkwiki-rec/'
        : '/',

    transpileDependencies: ['vuetify'],

    configureWebpack: {
        plugins: [
            new webpack.DefinePlugin({
                'process.env.PACKAGE_VERSION': JSON.stringify(version),
            }),
        ],
        experiments: {
            asyncWebAssembly: true,
        },
    },

    chainWebpack: (config) => {
        // PWA-Plugin entfernen
        config.plugins.delete('pwa');
    },

    pwa: {
        name: 'Folkwiki - search by recording',
        theme_color: '#055581',
        background_color: '#055581',
    },
};
