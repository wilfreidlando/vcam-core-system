/**
 * La feuille de style de la console opérateur.
 *
 * La version de Tailwind est celle que la console utilisait jusqu'ici
 * (3.4.16, voir `public/assets/admin/vendor/README.md`) : le jeu de
 * classes est donc identique, et ce qui change est seulement **qui**
 * compile — le fichier, ou le navigateur à chaque page.
 *
 * Le périmètre de scan est plus large que les seuls gabarits, et c'est
 * délibéré. Le Play CDN compilait **le DOM vivant** : une classe posée
 * par le JavaScript (`rotate-90` sur le chevron des détails) existait
 * dans le CSS sans jamais figurer dans un gabarit. Une compilation
 * statique ne lit que ses sources — il faut donc y inclure tout ce qui
 * peut porter une classe :
 *
 *  - `resources/views/**` : les gabarits, y compris les classes construites
 *    dans leurs blocs `@php` (`$toneRing`, `$barClass`, …) ;
 *  - `app/**` et `packages/**` : une classe pourrait être rendue par un
 *    contrôleur ou un view composer ;
 *  - `public/assets/admin/console.js` : les composants Alpine (`console.js`)
 *    posent `rotate-90` et `opacity-50` à l'exécution.
 *
 * Le coût de ce large périmètre est mesuré : +823 octets.
 *
 * @type {import('tailwindcss').Config}
 */
module.exports = {
  content: [
    './resources/views/**/*.blade.php',
    './app/**/*.php',
    './packages/**/*.php',
    './public/assets/admin/console.js',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
