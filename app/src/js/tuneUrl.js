export function tuneUrl(settingId) {
    if (settingId === undefined || settingId === null) {
        return null;
    }

    return 'http://www.folkwiki.se/Musik/' +
        encodeURIComponent(String(settingId));
}
