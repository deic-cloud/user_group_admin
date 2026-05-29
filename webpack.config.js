const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')

module.exports = (env, argv) => {
	const isDev = argv.mode === 'development'
	return {
		mode:    isDev ? 'development' : 'production',
		devtool: isDev ? 'cheap-source-map' : false,
		entry: {
			'main':             path.join(__dirname, 'src', 'main.js'),
			'files-navigation': path.join(__dirname, 'src', 'files-navigation.js'),
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
		plugins: [new VueLoaderPlugin()],
		externals: {
			vue: 'Vue',
			OC:  'OC',
			OCA: 'OCA',
			OCP: 'OCP',
		},
	}
}
