// assets/controllers/_register.js

export function registerAll(app, controllers) {
    for (const [name, controller] of Object.entries(controllers)) {
        app.register(name, controller);
    }
}