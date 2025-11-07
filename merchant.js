function onPaymentUpdate(data, code) {
    // console.log(code, data.payment.id)
    if (data.payment.id && data.passthrough) {
        let passthrough = JSON.parse(data.passthrough)
        jQuery.ajax({
            method: 'POST',
            url: '/?wc-api=cryptochill',
            data: JSON.stringify({action: 'update_invoice', invoice_id: data.payment.id, order_id: passthrough.order_id})
        }).done(function () {
            // console.log('invoice updated')
        })
    }
}


function onPaymentOpen(data, code) {
    console.log(code, data)
}

function onPaymentSuccess(data, code) {
    console.log(code, data.payment)
    if ((data.payment.payment_status === 'paid' || data.payment.payment_status === 'processing') && data.passthrough) {
        let passthrough = JSON.parse(data.passthrough)
        window.location.replace(passthrough.return_url)
    }

}

function onPaymentCancel(data, code) {
    console.log(code, data)
}


jQuery(function ($) {
    if (!merchant_params) return

    const passthrough = merchant_params.passthrough
    var sdk_params = {
        account: merchant_params.account,
        profile: merchant_params.profile,
        apiEndpoint: merchant_params.apiEndpoint,
        passthrough: passthrough,
        onOpen: onPaymentOpen,
        onSuccess: onPaymentSuccess,
        onUpdate: onPaymentUpdate,
        onCancel: onPaymentCancel
    }
    if(merchant_params.invoice){
        sdk_params.invoice = merchant_params.invoice
    }
    if(merchant_params.placement){
        sdk_params.placement = merchant_params.placement
    }
    if(merchant_params.placementTarget){
        sdk_params.placementTarget = merchant_params.placementTarget
    }
    if(Boolean(merchant_params.devMode)){
        sdk_params.devMode = true
    }
    SDK.setup(sdk_params)
    if(merchant_params.order) {
        SDK.open(merchant_params.order)
    }
})