(function () {
    function buildMap(el) {
        var lat = parseFloat(el.getAttribute('data-lat'));
        var lng = parseFloat(el.getAttribute('data-lng'));
        var pointsJson = el.getAttribute('data-points') || '[]';
        var points = [];

        try { points = JSON.parse(pointsJson); } catch (e) { points = []; }

        if (!isFinite(lat) || !isFinite(lng)) {
            el.innerHTML = '<p class="p-3 mb-0">No location has been added yet.</p>';
            return;
        }

        var map = L.map(el).setView([lat, lng], 10);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        if (points.length > 0) {
            var latLngs = points.map(function (p) { return [parseFloat(p.lat), parseFloat(p.lng)]; }).filter(function (p) { return isFinite(p[0]) && isFinite(p[1]); });
            latLngs.forEach(function (p, index) {
                L.marker(p).addTo(map).bindPopup(points[index].label || 'Check-in');
            });
            if (latLngs.length > 1) {
                L.polyline(latLngs, { weight: 4 }).addTo(map);
                map.fitBounds(latLngs, { padding: [20, 20] });
            }
        } else {
            L.marker([lat, lng]).addTo(map);
        }
    }

    document.querySelectorAll('[data-map="team"]')?.forEach(buildMap);
})();
