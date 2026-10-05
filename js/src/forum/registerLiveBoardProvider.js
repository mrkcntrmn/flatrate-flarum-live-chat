import { createLiveBoardProvider } from './liveBoardProvider.js';

/**
 * Expose app.flatRateLiveBoard for Navigation.
 * No poll starts until a Brand board calls activate().
 */
export default function registerLiveBoardProvider() {
    app.flatRateLiveBoard = createLiveBoardProvider({ app });
}
