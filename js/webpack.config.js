const config = require('flarum-webpack-config');

// Only `src/admin` exists, so only an admin bundle is built. The forum side of
// this extension is server-rendered CSS and needs no JavaScript at all.
module.exports = config();
