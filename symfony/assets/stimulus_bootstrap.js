import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();

import GrowthController from './controllers/growth_controller.js';
app.register('growth', GrowthController);
