const { defineConfig } = require('cypress')

module.exports = defineConfig({
  e2e: {
    baseUrl: process.env.NC_URL || 'https://testvox.hvanextcloudpoc.src.surf-hosted.nl',
    supportFile: 'cypress/support/e2e.js',
    specPattern: 'cypress/e2e/**/*.cy.js',
    viewportWidth: 1280,
    viewportHeight: 800,
    video: false,
    screenshotOnRunFailure: true,
    defaultCommandTimeout: 10000,
    requestTimeout: 10000,
    env: {
      NC_USER: process.env.NC_USER || 'admin',
      NC_PASSWORD: process.env.NC_PASSWORD || 'admin',
    },
  },
})
