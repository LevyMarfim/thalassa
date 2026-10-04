import { startStimulusApp } from '@symfony/stimulus-bundle';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);

import { registerSecurityControllers } from './controllers/security/stimulus_security.js';

registerSecurityControllers(app);
