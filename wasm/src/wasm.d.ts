declare module '*.wasm?url' {
  const url: string;
  export default url;
}
// @jsquash/avif ships declarations that reuse the global EmscriptenWasm
// namespace also declared by @jsquash/webp. Referencing both would merge two
// conflicting namespace aliases, so the deep imports this module needs are
// declared here instead. The single-threaded codec is imported directly: the
// bundled encoder must not depend on SharedArrayBuffer or COOP/COEP headers.
declare module '@jsquash/avif/codec/enc/avif_enc.js' {
  interface AvifModuleOptions {
    noInitialRun?: boolean;
    locateFile?: (path: string) => string;
  }
  interface AvifEncodedModule {
    encode(
      data: BufferSource,
      width: number,
      height: number,
      options: Record<string, unknown>,
    ): Uint8Array | null;
  }
  const factory: (options?: AvifModuleOptions) => Promise<AvifEncodedModule>;
  export default factory;
}
declare module '@jsquash/avif/meta.js' {
  export const defaultOptions: Record<string, unknown>;
}
