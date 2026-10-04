// assets/controllers/security/stimulus_security.js

import { registerAll } from '../_register.js';

import registration from './registration_controller.js';
import login from './login_controller.js';

export const controllers = {
    'registration': registration,
    'login': login,
};

export function registerSecurityControllers(app) {
    registerAll(app, controllers);
}