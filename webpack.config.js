const path = require('path')
const webpack = require('webpack')
const { VueLoaderPlugin } = require('vue-loader')
const { name: appName, version: appVersion } = require('./package.json')

module.exports = (env, argv) => {
	const isDev = argv.mode === 'development'
	return {
		mode:    isDev ? 'development' : 'production',
		devtool: isDev ? 'cheap-source-map' : false,
		entry: {
			'main':                  path.join(__dirname, 'src', 'main.js'),
			'files-navigation':      path.join(__dirname, 'src', 'files-navigation.js'),
			'files-navigation-init': path.join(__dirname, 'src', 'files-navigation-init.js'),
		},
		output: {
			path:     path.join(__dirname, 'js'),
			filename: '[name].js',
			clean:    false,
		},
		resolve: {
			extensions: ['.js', '.vue'],
			fallback: { stream: false },
		},
		optimization: {
			splitChunks: false,
		},
		module: {
			rules: [
				{
					// ?raw imports (e.g. SVG source strings)
					resourceQuery: /raw/,
					type: 'asset/source',
				},
				{
					test: /\.(svg|png|jpg|gif|woff2?|eot|ttf)$/,
					resourceQuery: { not: [/raw/] },
					type: 'asset/inline',
				},
				{
					test: /\.vue$/,
					loader: 'vue-loader',
				},
				{
					test: /\.js$/,
					exclude: /node_modules/,
					loader: 'babel-loader',
				},
				{
					test: /\.css$/,
					use: ['vue-style-loader', 'css-loader'],
				},
			],
		},
		plugins: [
			new VueLoaderPlugin(),
			new webpack.DefinePlugin({ appName: JSON.stringify(appName) }),
			new webpack.DefinePlugin({ appVersion: JSON.stringify(appVersion) }),
		],
		externals: {
			OC:  'OC',
			OCA: 'OCA',
			OCP: 'OCP',
		},
	}
}
