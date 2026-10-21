{{--
    Currency formatting for the client side.

    Fares on the booking screens are computed in the browser, so they cannot go
    through CabmeSettings::money(). This mirrors it exactly — same symbol, same
    decimals, same side — from the one place the admin configures it.
--}}
<script>
    window.cabmeMoney = (function () {
        var symbol = @json($settings->currency());
        var right = @json($settings->symbolAtRight());
        var decimals = @json($settings->decimals());

        return function (amount, opts) {
            var value = Number(amount ?? 0);
            if (!isFinite(value)) value = 0;

            var text = value.toFixed((opts && opts.decimals != null) ? opts.decimals : decimals);

            return right ? text + symbol : symbol + text;
        };
    })();
</script>
