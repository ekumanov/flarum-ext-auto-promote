/*
 * The extenders array must be exported under the name `extend`: the generated
 * entrypoint is `export * from './src/admin'`, and `export *` does not
 * re-export a module's default binding — so `export default [...]` here would
 * register the extension with an empty export object and silently drop every
 * setting and permission. This is the shape core's own extensions use.
 */
export { default as extend } from './extend';
