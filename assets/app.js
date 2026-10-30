import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
// import './styles/app.css';

// flatpickr's base styles. Its JavaScript is imported by the date_picker
// Stimulus controller; the stylesheet is pulled in here so importmap()
// emits the <link> tag for it.
import 'flatpickr/dist/flatpickr.min.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
