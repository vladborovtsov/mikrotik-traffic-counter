(function (root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    root.MikstatChartMode = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function isSafariBrowser(userAgent) {
        return /Safari\//.test(userAgent) && !/Chrome\/|Chromium\/|CriOS\/|Android/.test(userAgent);
    }

    function shouldUseSimpleDetailChart(userAgent, pointCount) {
        void pointCount;

        // Safari can crash when the detail chart uses Dashboard + ChartRangeFilter
        // with HTML tooltips. Keep the simplified chart path unless that stack is
        // explicitly revalidated in Safari.
        return isSafariBrowser(userAgent);
    }

    return {
        isSafariBrowser,
        shouldUseSimpleDetailChart
    };
}));
