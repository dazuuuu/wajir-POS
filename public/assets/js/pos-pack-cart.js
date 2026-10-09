(function (global) {
    function buckets() {
        // over*: owner's adjusted price for this sale only (per item / box / pack)
        return { retail: 0, retailPack: 0, wholesale: 0, customUnitPrice: null, overRetail: null, overRetailPack: null, overWholesale: null };
    }
    function hasRetailPack(p) {
        return !!(p && p.packUnit && p.unitsPerPack > 1 && p.retailPackPrice > 0);
    }
    function hasWholesalePack(p) {
        return !!(p && p.packUnit && p.unitsPerPack > 1 && p.packPrice > 0);
    }
    function packLabel(p) {
        return (p && p.packUnit) ? p.packUnit : 'box';
    }
    // While offline the device can't know the real stock (deliveries, other
    // tills), so like a supermarket till it doesn't block a sale on it; the
    // server records offline sales even past its count (shows as negative).
    var ignoreStock = false;
    function setIgnoreStock(on) { ignoreStock = !!on; }
    function stockOf(p) { return ignoreStock ? 1e9 : (p ? p.stock : 0); }
    function stockFromPacks(p, packs) {
        packs = parseFloat(packs) || 0;
        if (!p || !(p.unitsPerPack > 1)) return packs;
        return Math.round(packs * p.unitsPerPack * 100) / 100;
    }
    function otherStock(p, c, except) {
        var used = 0;
        if (except !== 'retail') used += (c.retail || 0);
        if (except !== 'retailPack' && hasRetailPack(p)) used += stockFromPacks(p, c.retailPack || 0);
        if (except !== 'wholesale') {
            used += hasWholesalePack(p) ? stockFromPacks(p, c.wholesale || 0) : (c.wholesale || 0);
        }
        return used;
    }
    function stockUsed(p, c) {
        if (!p || !c) return 0;
        return otherStock(p, c, '');
    }
    function maxRetail(p, c) {
        return Math.max(0, Math.round((stockOf(p) - otherStock(p, c, 'retail')) * 100) / 100);
    }
    function maxRetailPack(p, c) {
        if (!hasRetailPack(p)) return 0;
        var left = Math.max(0, stockOf(p) - otherStock(p, c, 'retailPack'));
        return Math.floor((left / p.unitsPerPack) * 100) / 100;
    }
    function maxWholesale(p, c) {
        var left = Math.max(0, stockOf(p) - otherStock(p, c, 'wholesale'));
        if (hasWholesalePack(p)) return Math.floor((left / p.unitsPerPack) * 100) / 100;
        return Math.round(left * 100) / 100;
    }
    function productPrice(p, type) {
        if (type === 'retail_pack' && hasRetailPack(p)) return p.retailPackPrice;
        if (type === 'wholesale' && hasWholesalePack(p)) return p.packPrice;
        if (type === 'wholesale' && p.wholesale > 0) return p.wholesale;
        return p.price;
    }
    function retailPieces(p, c) {
        return (c.retail || 0) + (hasRetailPack(p) ? stockFromPacks(p, c.retailPack || 0) : 0);
    }
    function retailLineTotal(p, pieces) {
        pieces = parseFloat(pieces) || 0;
        if (pieces <= 0) return 0;
        return applyTiersTotal(p, pieces, pieces * (p.price || 0));
    }
    function applyTiersTotal(p, qty, base) {
        qty = parseFloat(qty) || 0;
        base = parseFloat(base) || 0;
        if (qty <= 0 || base <= 0) return Math.max(0, base);
        var tiers = Array.isArray(p && p.tiers) ? p.tiers : [];
        if (!tiers.length) return Math.round(base * 100) / 100;
        var unitsPerPack = (p && p.unitsPerPack > 1) ? p.unitsPerPack : 1;
        var bestUnit = null;
        var amountOff = 0;
        tiers.forEach(function (tier) {
            if (!tierMatches(tier, qty, unitsPerPack)) return;
            var unitPrice = parseFloat(tier.unit_price);
            if (!isNaN(unitPrice) && unitPrice > 0 && (bestUnit === null || unitPrice < bestUnit)) {
                bestUnit = unitPrice;
            }
            var off = parseFloat(tier.discount_amount) || 0;
            if (off > amountOff) amountOff = off;
        });
        var total = bestUnit !== null ? (bestUnit * qty) : base;
        return Math.round(Math.max(0, total - amountOff) * 100) / 100;
    }
    function tierMatches(tier, qty, unitsPerPack) {
        if (qtyInRange(tier, qty)) return true;
        if (unitsPerPack > 1.0001) {
            return qtyInRange(tier, qty / unitsPerPack);
        }
        return false;
    }
    function qtyInRange(tier, qty) {
        var min = parseFloat(tier.min_qty) || 0;
        var max = (tier.max_qty === null || tier.max_qty === undefined || tier.max_qty === '')
            ? null : parseFloat(tier.max_qty);
        if (qty + 0.0001 < min) return false;
        if (max !== null && !isNaN(max) && qty > max + 0.0001) return false;
        return true;
    }
    /** Owner's adjusted price for one bucket, or null for the normal price. */
    function override(c, field) {
        var key = field === 'retailPack' ? 'overRetailPack' : (field === 'wholesale' ? 'overWholesale' : 'overRetail');
        var v = parseFloat(c && c[key]);
        return isNaN(v) || v < 0 ? null : v;
    }
    function lineTotal(p, c) {
        var retailQty = c.retail || 0;
        var oR = override(c, 'retail'), oP = override(c, 'retailPack'), oW = override(c, 'wholesale');
        if (oR !== null || oP !== null || oW !== null) {
            var t = 0;
            if (retailQty > 0) t += oR !== null ? retailQty * oR : retailLineTotal(p, retailQty);
            if ((c.retailPack || 0) > 0 && hasRetailPack(p)) {
                t += oP !== null ? (c.retailPack || 0) * oP
                    : applyTiersTotal(p, stockFromPacks(p, c.retailPack), (c.retailPack || 0) * productPrice(p, 'retail_pack'));
            }
            if ((c.wholesale || 0) > 0) {
                t += oW !== null ? (c.wholesale || 0) * oW
                    : applyTiersTotal(p, hasWholesalePack(p) ? stockFromPacks(p, c.wholesale) : (c.wholesale || 0), (c.wholesale || 0) * productPrice(p, 'wholesale'));
            }
            return Math.round(t * 100) / 100;
        }
        var total = retailQty > 0 && parseFloat(c.customUnitPrice) >= (p.price || 0)
            ? Math.round(retailQty * parseFloat(c.customUnitPrice) * 100) / 100
            : retailLineTotal(p, retailQty);
        if ((c.retailPack || 0) > 0 && hasRetailPack(p)) {
            var packPieces = stockFromPacks(p, c.retailPack);
            total += applyTiersTotal(p, packPieces, (c.retailPack || 0) * productPrice(p, 'retail_pack'));
        }
        if ((c.wholesale || 0) > 0) {
            var wPieces = hasWholesalePack(p) ? stockFromPacks(p, c.wholesale) : (c.wholesale || 0);
            var wBase = (c.wholesale || 0) * productPrice(p, 'wholesale');
            total += applyTiersTotal(p, wPieces, wBase);
        }
        return Math.round(total * 100) / 100;
    }
    function serialize(cart, products, order) {
        var out = [];
        var keys = (Array.isArray(order) && order.length) ? order : Object.keys(cart);
        keys.forEach(function (id) {
            var p = products[id], c = cart[id];
            if (!p || !c) return;
            if ((c.retail || 0) > 0) {
                var retailLine = { product_id: parseInt(id, 10), quantity: c.retail, price_type: 'retail' };
                if (override(c, 'retail') !== null) retailLine.override_total = Math.round(c.retail * override(c, 'retail') * 100) / 100;
                else if (parseFloat(c.customUnitPrice) > 0) retailLine.unit_price = parseFloat(c.customUnitPrice);
                out.push(retailLine);
            }
            if ((c.retailPack || 0) > 0 && hasRetailPack(p)) {
                var packLine = {
                    product_id: parseInt(id, 10),
                    quantity: stockFromPacks(p, c.retailPack),
                    price_type: 'retail_pack',
                    quantity_mode: 'inner'
                };
                if (override(c, 'retailPack') !== null) packLine.override_total = Math.round(c.retailPack * override(c, 'retailPack') * 100) / 100;
                out.push(packLine);
            }
            if ((c.wholesale || 0) > 0) {
                var wLine = {
                    product_id: parseInt(id, 10),
                    quantity: hasWholesalePack(p) ? stockFromPacks(p, c.wholesale) : c.wholesale,
                    price_type: 'wholesale',
                    quantity_mode: hasWholesalePack(p) ? 'inner' : 'unit'
                };
                if (override(c, 'wholesale') !== null) wLine.override_total = Math.round(c.wholesale * override(c, 'wholesale') * 100) / 100;
                out.push(wLine);
            }
        });
        return out;
    }
    function isEmpty(c) {
        return !c || ((c.retail || 0) <= 0 && (c.retailPack || 0) <= 0 && (c.wholesale || 0) <= 0);
    }
    function applyLine(cart, line, product) {
        var id = String(line.product_id);
        if (!cart[id]) cart[id] = buckets();
        var qty = parseFloat(line.quantity) || 0;
        if (line.price_type === 'wholesale') {
            if (line.quantity_mode === 'inner' && product && product.unitsPerPack > 1) {
                qty = Math.round((qty / product.unitsPerPack) * 100) / 100;
            }
            cart[id].wholesale += qty;
        }
        else if (line.price_type === 'retail_pack') {
            if (line.quantity_mode === 'inner' && product && product.unitsPerPack > 1) {
                qty = Math.round((qty / product.unitsPerPack) * 100) / 100;
            }
            cart[id].retailPack += qty;
        }
        else cart[id].retail += qty;
    }
    function clampField(p, c, field, val) {
        val = Math.round((parseFloat(val) || 0) * 100) / 100;
        var max = field === 'retailPack' ? maxRetailPack(p, c)
            : (field === 'wholesale' ? maxWholesale(p, c) : maxRetail(p, c));
        if (val > max) val = max;
        return val > 0 ? val : 0;
    }
    function qtyRow(id, label, priceText, field, value, max) {
        var attr = field === 'retailPack' ? 'retail-pack' : field;
        return '<label class="pos-dual-row"><span class="pos-dual-label">' + label
            + ' <span class="text-muted">(' + priceText + ')</span></span>'
            + '<span class="pos-qty"><button type="button" data-dec-' + attr + '="' + id + '">−</button>'
            + '<button type="button" class="pos-half-btn" data-half-' + attr + '="' + id + '" title="Add half">½</button>'
            + '<input type="number" step="0.5" min="0" max="' + max + '" class="pos-qty-input" data-' + attr + '-qty="' + id + '" value="' + value + '" inputmode="decimal">'
            + '<button type="button" data-inc-' + attr + '="' + id + '">+</button></span></label>';
    }

    /* ---- Owner's per-sale price adjustment (till + credit sale) ---- */
    var OVER_KEY = { retail: 'overRetail', retailPack: 'overRetailPack', wholesale: 'overWholesale' };
    var TYPE_OF = { retail: 'retail', retailPack: 'retail_pack', wholesale: 'wholesale' };
    function isAdjusted(c) {
        return override(c, 'retail') !== null || override(c, 'retailPack') !== null || override(c, 'wholesale') !== null;
    }
    /** Price shown next to a quantity: the adjusted one when set. */
    function shownPrice(p, c, field) {
        var o = override(c, field);
        return o !== null ? o : productPrice(p, TYPE_OF[field]);
    }
    /**
     * Quiet controls for one cart line: {inline} goes after the line total
     * (a small "adjust price" link), {block} is the open editor.
     */
    function priceEditor(p, c, id) {
        if (c.editPrice) {
            var wLabel = hasWholesalePack(p) ? packLabel(p) : 'item';
            var row = function (f, label) {
                var normal = productPrice(p, TYPE_OF[f]);
                return '<label class="pos-price-row"><span>' + label + '</span>'
                    + '<input type="number" step="0.01" min="0" inputmode="decimal" class="form-control form-control-sm"'
                    + ' data-price-field="' + f + '" data-price-id="' + id + '" value="' + shownPrice(p, c, f) + '" placeholder="' + normal + '"></label>';
            };
            return { inline: '', block: '<div class="pos-price-edit">'
                + ((c.retail || 0) > 0 ? row('retail', 'Price per item') : '')
                + ((c.retailPack || 0) > 0 && hasRetailPack(p) ? row('retailPack', 'Price per ' + packLabel(p)) : '')
                + ((c.wholesale || 0) > 0 ? row('wholesale', 'Wholesale per ' + wLabel) : '')
                + '<div class="pos-price-links"><button type="button" class="pos-link" data-price-done="' + id + '">Done</button>'
                + (isAdjusted(c) ? ' · <button type="button" class="pos-link" data-price-reset="' + id + '">Normal price</button>' : '')
                + '</div></div>' };
        }
        return { inline: ' · <button type="button" class="pos-link" data-price-open="' + id + '">' + (isAdjusted(c) ? 'price adjusted' : 'adjust price') + '</button>', block: '' };
    }
    /** Typed price → override; the normal price (or blank) means no adjustment. */
    function setOverrideFrom(input, p, c) {
        var f = input.dataset.priceField;
        var normal = productPrice(p, TYPE_OF[f]);
        var v = parseFloat(input.value);
        c[OVER_KEY[f]] = (input.value === '' || isNaN(v) || v < 0 || Math.abs(v - normal) < 0.005) ? null : Math.round(v * 100) / 100;
    }
    /**
     * Wires the editor inside the cart container.
     * opts: { products, getLine(id) → cart bucket, render(), live() → refresh totals while typing }
     */
    function wirePriceEditor(container, opts) {
        if (!container) return;
        var focus = function (id) {
            var el = container.querySelector('[data-price-id="' + id + '"]');
            if (el) { el.focus(); el.select(); }
        };
        var apply = function (input) {
            var p = opts.products[input.dataset.priceId];
            if (p) setOverrideFrom(input, p, opts.getLine(input.dataset.priceId));
        };
        container.addEventListener('click', function (e) {
            var t = e.target.closest('button');
            if (!t) return;
            if (t.dataset.priceOpen) { opts.getLine(t.dataset.priceOpen).editPrice = true; opts.render(); focus(t.dataset.priceOpen); }
            else if (t.dataset.priceDone) { opts.getLine(t.dataset.priceDone).editPrice = false; opts.render(); }
            else if (t.dataset.priceReset) {
                var c = opts.getLine(t.dataset.priceReset);
                c.overRetail = c.overRetailPack = c.overWholesale = null;
                c.editPrice = false;
                opts.render();
            }
        });
        container.addEventListener('input', function (e) {
            var input = e.target.closest('[data-price-field]');
            if (input) { apply(input); opts.live(); }
        });
        container.addEventListener('change', function (e) {
            var input = e.target.closest('[data-price-field]');
            if (input) { apply(input); opts.render(); }
        });
        container.addEventListener('keydown', function (e) {
            var input = e.target.closest('[data-price-field]');
            if (input && e.key === 'Enter') {
                e.preventDefault();
                apply(input);
                opts.getLine(input.dataset.priceId).editPrice = false;
                opts.render();
            }
        });
    }
    var PRICE_CSS = '.pos-link{background:none;border:0;padding:0;color:#64748b;font-size:.78rem;text-decoration:underline dotted;cursor:pointer}'
        + '.pos-link:hover{color:#334155}'
        + '.pos-price-edit{margin-top:6px;padding:8px;background:#f8fafc;border:1px solid #eef0f4;border-radius:8px}'
        + '.pos-price-row{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:.8rem;color:#475569;margin-bottom:6px}'
        + '.pos-price-row input{max-width:130px}.pos-price-links{font-size:.78rem;color:#94a3b8}';
    if (global.document && !document.getElementById('pos-price-css')) {
        var st = document.createElement('style');
        st.id = 'pos-price-css';
        st.textContent = PRICE_CSS;
        (document.head || document.documentElement).appendChild(st);
    }

    global.PosPackCart = {
        setIgnoreStock: setIgnoreStock,
        stockOf: stockOf,
        shownPrice: shownPrice,
        isAdjusted: isAdjusted,
        priceEditor: priceEditor,
        wirePriceEditor: wirePriceEditor,
        buckets: buckets,
        hasRetailPack: hasRetailPack,
        hasWholesalePack: hasWholesalePack,
        packLabel: packLabel,
        stockUsed: stockUsed,
        maxRetail: maxRetail,
        maxRetailPack: maxRetailPack,
        maxWholesale: maxWholesale,
        productPrice: productPrice,
        retailPieces: retailPieces,
        lineTotal: lineTotal,
        override: override,
        serialize: serialize,
        isEmpty: isEmpty,
        applyLine: applyLine,
        clampField: clampField,
        qtyRow: qtyRow
    };
})(window);
