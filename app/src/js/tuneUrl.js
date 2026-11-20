export function tuneUrl(setting) {
    const base = 'https://www.abctransposer.de/index.php?tunebook=';

    if (!setting || !setting.tunebook) {
        return null;
    }

    const url = base + encodeURIComponent(setting.tunebook);

    if (setting.group) {
        const setUrl = url + '&set=' + encodeURIComponent(setting.group);

        if (setting.settuneindex !== undefined && setting.settuneindex !== null) {
            return setUrl + '&settuneindex=' + encodeURIComponent(setting.settuneindex);
        }

        return setUrl;
    }

    if (setting.index !== undefined && setting.index !== null) {
        return url + '&tune=' + encodeURIComponent(setting.index);
    }

    return null;
}
