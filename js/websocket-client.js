let ws = new WebSocket("192.168.137.1"); // Replace with your IP/domain!

ws.onopen = function() {
    console.log("WebSocket connected!");
};

ws.onmessage = function(event) {
    try {
        const data = JSON.parse(event.data);
        // Gamitin ang format sa iyong UI, halimbawa toast
        if (data.type === 'notification') {
            // showToast ay defined na sa notifications.php page mo!
            if (typeof showToast === "function") {
                showToast(data.title + ": " + data.message, data.notification_type || 'info');
            } else {
                alert(data.title + ": " + data.message);
            }
        }
    } catch (e) {
        // fallback
        alert(event.data);
    }
};

ws.onclose = function() {
    console.log("WebSocket closed!");
};
ws.onerror = function(error) {
    console.log("WebSocket error: ", error);
};