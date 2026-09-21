import { createTranslator } from '@symfony/ux-translator';
import { messages, localeFallbacks } from '../var/translations/index.js';

const translator = createTranslator({
    messages,
    localeFallbacks,
});

export const { trans } = translator;
