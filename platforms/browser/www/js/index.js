var app = {
    // Application Constructor
    initialize: function () {
        this.bindEvents();
    },
    // Bind Event Listeners
    //
    // Bind any events that are required on startup. Common events are:
    // 'load', 'deviceready', 'offline', and 'online'.
    bindEvents: function () {
        document.addEventListener('deviceready', this.onDeviceReady, false);
    },
    // deviceready Event Handler
    //
    // The scope of 'this' is the event. In order to call the 'receivedEvent'
    // function, we must explicitly call 'app.receivedEvent(...);'
    onDeviceReady: function () {
        app.receivedEvent('deviceready');
    },
    // Update DOM on a Received Event
    receivedEvent: function (id) {
        var parentElement = document.getElementById(id);
        var listeningElement = parentElement.querySelector('.listening');
        var receivedElement = parentElement.querySelector('.received');

        listeningElement.setAttribute('style', 'display:none;');
        receivedElement.setAttribute('style', 'display:block;');

        console.log('Received Event: ' + id);
    }
};

$('#getSWData').on('click', function () {
    var data = {
        "filter": {
            "0": {
                "property": "mainDetail.ean",
                "expression": "=",
                "value": "4001234567891"
            }
        }
    };    
    
    $.ajax({
        method: "GET",
        contentType: "application/json; charset=utf-8",
        username: "demo",
        password: "UaN5TTIn3hUaOOc1vaPBJTGRKs9DsCDGVHeaTpQb",
        data: data,
        url: "http://facettennavigation.rudde.de.blmedia02.virtualhosts.de/api/articles/"
    }).done(function (msg) {
        $('#SWData').html(JSON.stringify(msg));
    });
});

$('#start-scan').on('click', function () {


    cordova.plugins.barcodeScanner.scan(
            function (result) {
                //alert("Barcode erkannt: \n" + "Code: " + result.text + "\n" + "Format: " + result.format + "\n" + "Abgebrochen: " + result.cancelled);
                $("#BarcodeData").val(result.text);
            },
            function (error) {
                alert("Scanning failed: " + error);
            },
            {
                preferFrontCamera: false, // iOS and Android
                showFlipCameraButton: true, // iOS and Android
                showTorchButton: true, // iOS and Android
                torchOn: true, // Android, launch with the torch switched on (if available)
                prompt: "Bitte Scanfeld über den Barcode positionieren!", // Android
                resultDisplayDuration: 500, // Android, display scanned text for X ms. 0 suppresses it entirely, default 1500
                //formats : "QR_CODE,PDF_417", // default: all but PDF_417 and RSS_EXPANDED
                //orientation: "landscape", // Android only (portrait|landscape), default unset so it rotates with the device
                disableAnimations: true, // iOS
                disableSuccessBeep: false // iOS
            }
    );
});