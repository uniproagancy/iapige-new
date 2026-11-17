// The icon opens the panel, or brings back the one already open: two panels
// would run two collections over the same saved list.
chrome.action.onClicked.addListener(async () => {
    const url = chrome.runtime.getURL('panel.html');
    const {panelTab} = await chrome.storage.session.get('panelTab');

    if (Number.isInteger(panelTab)) {
        try {
            const tab = await chrome.tabs.get(panelTab);

            if (tab.url === url) {
                await chrome.tabs.update(tab.id, {active: true});
                await chrome.windows.update(tab.windowId, {focused: true});

                return;
            }
        } catch {
            // That panel was closed; a new one is opened below.
        }
    }

    const tab = await chrome.tabs.create({url, active: true});
    await chrome.storage.session.set({panelTab: tab.id});
});
