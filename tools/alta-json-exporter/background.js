chrome.action.onClicked.addListener(() => void (async () => {
    const url = chrome.runtime.getURL('panel.html');
    const {altaJsonExporterPanel} = await chrome.storage.session.get('altaJsonExporterPanel');
    if (Number.isInteger(altaJsonExporterPanel)) {
        try {
            const tab = await chrome.tabs.get(altaJsonExporterPanel);
            if (tab.url === url) {
                await chrome.tabs.update(tab.id, {active: true});
                return;
            }
        } catch { /* The prior panel was closed. */ }
    }
    const tab = await chrome.tabs.create({url, active: true});
    await chrome.storage.session.set({altaJsonExporterPanel: tab.id});
})());
