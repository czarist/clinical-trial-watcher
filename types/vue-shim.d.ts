// Used only by tsconfig.ts.json (TypeScript 7), which cannot read .vue files.
// vue-tsc checks the components themselves and does not include this file.
declare module '*.vue' {
    import type { DefineComponent } from 'vue';

    const component: DefineComponent;
    export default component;
}
