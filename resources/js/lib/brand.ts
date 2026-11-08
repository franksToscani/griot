// The provisional logo of Griot (docs/brand.md): the files made by scripts/brand-assets.php from
// the original, which stays outside the repository, imported with Vite (their names get a hash,
// so a cache never keeps an old one). To change the logo, run the script again with the new
// original: the names, the sizes and this module stay the same.
import pin1x from '../../images/brand/pin-1x.webp';
import pin2x from '../../images/brand/pin-2x.webp';
import pin3x from '../../images/brand/pin-3x.webp';
import wordmarkDark1x from '../../images/brand/wordmark-dark-1x.webp';
import wordmarkDark2x from '../../images/brand/wordmark-dark-2x.webp';
import wordmarkDark3x from '../../images/brand/wordmark-dark-3x.webp';
import wordmarkLight1x from '../../images/brand/wordmark-light-1x.webp';
import wordmarkLight2x from '../../images/brand/wordmark-light-2x.webp';
import wordmarkLight3x from '../../images/brand/wordmark-light-3x.webp';

// An image at 1x, 2x and 3x: src the 1x one, srcSet all of them, the size of the 1x one (the
// size it is drawn at, for width and height: the page keeps its room while it loads).
export type BrandImage = {
    src: string;
    srcSet: string;
    width: number;
    height: number;
};

function densities(
    width: number,
    height: number,
    [one, two, three]: [string, string, string],
): BrandImage {
    return {
        src: one,
        srcSet: `${one} 1x, ${two} 2x, ${three} 3x`,
        width,
        height,
    };
}

// The word GRIOT with its pin, 36 px high at 1x: brown letters for the light theme, cream
// letters for the dark one, the orange pin in both.
export const WORDMARK = {
    light: densities(150, 36, [
        wordmarkLight1x,
        wordmarkLight2x,
        wordmarkLight3x,
    ]),
    dark: densities(150, 36, [wordmarkDark1x, wordmarkDark2x, wordmarkDark3x]),
};

// The pin alone, 24 px high at 1x, on the brown square of the small mark (AppLogoIcon).
export const PIN = densities(22, 24, [pin1x, pin2x, pin3x]);
