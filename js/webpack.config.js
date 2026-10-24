const config = require('flarum-webpack-config');

const NS = 'ernestdefoe-logo-manager';

/*
 * Only `src/admin` exists, so only an admin bundle is built. The forum side of
 * this extension is server-rendered CSS and needs no JavaScript at all.
 *
 * 🚨 The lazy tabs need chunk ids no other extension can have. Flarum's
 * registry finds a chunk by its id alone and every extension shares one
 * chunk-loading array, and webpack's default ids are 3-digit hashes — two
 * extensions can draw the same one, and then the wrong file is fetched or the
 * chunk counts as already loaded. So: ids prefixed with this extension's id,
 * and a chunk-loading global of our own.
 */
class NamespacedChunkIds {
  apply(compiler) {
    compiler.hooks.compilation.tap('NamespacedChunkIds', (compilation) => {
      compilation.hooks.chunkIds.tap('NamespacedChunkIds', (chunks) => {
        for (const chunk of chunks) {
          if (chunk.id === null) {
            chunk.id = `${NS}:${chunk.name || chunk.debugId}`;
            chunk.ids = [chunk.id];
          }
        }
      });
    });
  }
}

module.exports = (...args) => {
  let base = config();
  if (typeof base === 'function') base = base(...args);

  base.optimization = { ...base.optimization, chunkIds: false };
  base.output = { ...base.output, chunkLoadingGlobal: 'webpackChunk_ernestdefoe_logo_manager' };
  base.plugins = [...(base.plugins || []), new NamespacedChunkIds()];

  return base;
};
