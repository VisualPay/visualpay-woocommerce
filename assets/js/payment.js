(function ($) {
  "use strict";

  if (typeof VisualPayPay === "undefined") {
    return;
  }

  const cfg = VisualPayPay;
  const STATUS_POLL_MS = cfg.pollMs || 15000;

  let currencies = [];
  let currentTx = null;
  let selectedCurrency = null;
  let selectedNetwork = null;
  let statusTimer = null;
  let expireTimer = null;
  let paymentModal = null;
  let confirmCancelModal = null;
  let statusResolved = false;
  let statusCheckInFlight = false;

  function commonData(extra) {
    return $.extend(
      {
        order_id: cfg.orderId,
        order_key: cfg.orderKey,
        nonce: cfg.nonce,
      },
      extra || {}
    );
  }

  function showLoading(show) {
    $("#loading").toggleClass("show", !!show);
  }

  function showToast(message, isError) {
    const $t = $("#toast");
    $t.removeClass("alert-danger alert-success")
      .addClass(isError === false ? "alert-success" : "alert-danger")
      .text(message)
      .fadeIn(150);
    setTimeout(function () {
      $t.fadeOut(200);
    }, 4200);
  }

  function formatAmount(value, symbol) {
    if (value === null || value === undefined || value === "") {
      return symbol ? "— " + symbol : "—";
    }
    const n = Number(value);
    if (Number.isNaN(n)) return String(value) + (symbol ? " " + symbol : "");
    const raw = n % 1 === 0 ? n.toString() : n.toFixed(8).replace(/\.?0+$/, "");
    const parts = raw.split(".");
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    return parts.join(".") + (symbol ? " " + symbol : "");
  }

  function trimZeros(value) {
    const n = Number(value);
    if (Number.isNaN(n)) return String(value);
    return n % 1 === 0 ? String(n) : n.toFixed(8).replace(/\.?0+$/, "");
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function safeHttpUrl(url) {
    if (!url || typeof url !== "string") return "";
    try {
      const parsed = new URL(url, window.location.origin);
      if (parsed.protocol === "http:" || parsed.protocol === "https:") {
        return parsed.href;
      }
    } catch (err) {
      return "";
    }
    return "";
  }

  function safeImageUrl(url) {
    if (!url || typeof url !== "string") return "";
    try {
      const parsed = new URL(url, window.location.origin);
      if (parsed.protocol === "https:") {
        return parsed.href;
      }
    } catch (err) {
      return "";
    }
    return "";
  }

  function setSelectIcon($img, url) {
    const safe = safeImageUrl(url);
    if (safe) {
      $img.attr("src", safe).removeClass("d-none");
    } else {
      $img.addClass("d-none").attr("src", "");
    }
  }

  function setWarnHtml($el, currencySymbol, networkLabel) {
    const template = String(
      cfg.i18n.onlySend ||
        "Only send %1$s on the %2$s network to this address. Sending any other asset or using a different network may result in permanent loss of funds."
    ).replace(/<[^>]*>/g, "");
    const html = escapeHtml(template)
      .replace("%1$s", "<strong>" + escapeHtml(currencySymbol) + "</strong>")
      .replace("%2$s", "<strong>" + escapeHtml(networkLabel) + "</strong>");
    $el.html(html);
  }

  function setModalWarnHtml(currencySymbol, networkName) {
    const template = String(
      cfg.i18n.onlySendModal ||
        "Only send %1$s on %2$s to this address. Other assets or networks may result in permanent loss."
    ).replace(/<[^>]*>/g, "");
    const html = escapeHtml(template)
      .replace("%1$s", "<strong>" + escapeHtml(currencySymbol) + "</strong>")
      .replace("%2$s", "<strong>" + escapeHtml(networkName) + "</strong>");
    $("#modalWarnText").html(html);
  }

  function updateWarnTexts() {
    if (!selectedCurrency || !selectedNetwork) {
      $("#formWarnText").text(cfg.i18n.selectCryptoWarn || "");
      $("#modalWarnText").text(cfg.i18n.selectCryptoWarn || "");
      return;
    }
    const networkLabel =
      selectedNetwork.network_name +
      (selectedNetwork.network_code ? " (" + selectedNetwork.network_code.toUpperCase() + ")" : "");
    setWarnHtml($("#formWarnText"), selectedCurrency.currency_symbol, networkLabel);
    setModalWarnHtml(selectedCurrency.currency_symbol, selectedNetwork.network_name || networkLabel);
  }

  function updateApprox() {
    if (!selectedCurrency || !selectedNetwork) {
      $("#approxText").text(cfg.i18n.approxDash || "");
      return;
    }

    const usd = Number(cfg.amountUsd);
    const price = Number(selectedCurrency.price_in_usdt) || 1;

    if (!usd || usd <= 0) {
      $("#approxText").text(cfg.i18n.approxDash || "");
      return;
    }

    const crypto = usd / price;
    $("#approxText").text(
      (cfg.i18n.approxPrefix || "You will pay approximately") +
        " " +
        trimZeros(crypto) +
        " " +
        selectedCurrency.currency_symbol
    );
  }

  function populateNetworks(currency) {
    const $net = $("#networkSelect").empty();
    selectedNetwork = null;

    if (!currency || !currency.supported_networks || !currency.supported_networks.length) {
      $net.append($("<option>").val("").text(cfg.i18n.noNetworks || "No networks available"));
      setSelectIcon($("#networkIcon"), null);
      updateWarnTexts();
      updateApprox();
      return;
    }

    currency.supported_networks.forEach(function (net, idx) {
      const label = net.network_code
        ? net.network_code.toUpperCase() + " (" + net.network_name + ")"
        : net.network_name;
      $net.append(
        $("<option>")
          .val(net.network_code)
          .text(label)
          .attr("data-index", idx)
      );
    });

    $net.prop("selectedIndex", 0).trigger("change");
  }

  function loadCurrencies() {
    showLoading(true);
    $.ajax({
      url: cfg.ajaxUrl,
      method: "POST",
      data: commonData({ action: "visualpay_currencies" }),
      success: function (res) {
        if (!res || !res.success || !Array.isArray(res.data)) {
          showToast((res && res.data && res.data.message) || cfg.i18n.currenciesFail);
          $("#currencySelect").html(
            $("<option>").val("").text(cfg.i18n.failedToLoad || "Failed to load")
          );
          return;
        }

        currencies = res.data;
        const $cur = $("#currencySelect").empty();

        if (!currencies.length) {
          $cur.append($("<option>").val("").text(cfg.i18n.noCurrencies || "No currencies available"));
          if (cfg.existingTx && cfg.existingTx.tracking_code && !currentTx) {
            fillPendingModal(cfg.existingTx);
            startStatusPolling();
          }
          return;
        }

        currencies.forEach(function (c, idx) {
          $cur.append(
            $("<option>")
              .val(c.currency_symbol)
              .text(c.currency_symbol + " (" + c.currency_name + ")")
              .attr("data-index", idx)
          );
        });

        $cur.prop("selectedIndex", 0).trigger("change");

        if (cfg.existingTx && cfg.existingTx.tracking_code && !currentTx) {
          fillPendingModal(cfg.existingTx);
          startStatusPolling();
        }
      },
      error: function (xhr) {
        const msg =
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
          cfg.i18n.currenciesFail;
        showToast(msg);
        $("#currencySelect").html(
          $("<option>").val("").text(cfg.i18n.failedToLoad || "Failed to load")
        );

        if (cfg.existingTx && cfg.existingTx.tracking_code && !currentTx) {
          fillPendingModal(cfg.existingTx);
          startStatusPolling();
        }
      },
      complete: function () {
        showLoading(false);
      },
    });
  }

  function clearTimers() {
    if (statusTimer) {
      clearInterval(statusTimer);
      statusTimer = null;
    }
    if (expireTimer) {
      clearInterval(expireTimer);
      expireTimer = null;
    }
  }

  function stopStatusChecks() {
    statusResolved = true;
    statusCheckInFlight = false;
    clearTimers();
  }

  function showModalView(view) {
    $("#modalPending, #modalSuccess, #modalFailed").addClass("d-none");
    if (view === "pending") $("#modalPending").removeClass("d-none");
    if (view === "success") $("#modalSuccess").removeClass("d-none");
    if (view === "failed") $("#modalFailed").removeClass("d-none");
  }

  function renderQr(payload) {
    const $wrap = $("#qrWrap");
    $wrap.empty();

    if (payload && String(payload).indexOf("data:image") === 0) {
      if (/^data:image\/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+/=\r\n]+$/.test(String(payload))) {
        $wrap.append($("<img>").attr("alt", "QR Code").attr("src", payload));
      }
      return;
    }

    $wrap.empty().append($("<canvas>").attr("id", "qrCanvas"));
    const target = document.getElementById("qrCanvas");
    const text = payload || $("#walletAddress").val() || "";
    if (!text || typeof QRCode === "undefined") return;

    QRCode.toCanvas(
      target,
      text,
      {
        width: 200,
        margin: 1,
        color: { dark: "#000000", light: "#ffffff" },
      },
      function (err) {
        if (err) console.error(err);
      }
    );
  }

  function updateExpireCountdown(expiresAt) {
    if (expireTimer) {
      clearInterval(expireTimer);
      expireTimer = null;
    }

    function tick() {
      if (statusResolved) {
        if (expireTimer) {
          clearInterval(expireTimer);
          expireTimer = null;
        }
        return;
      }

      if (!expiresAt) {
        $("#expireTimer").text("--:--");
        return;
      }

      const now = Math.floor(Date.now() / 1000);
      let left = expiresAt - now;

      if (left <= 0) {
        $("#expireTimer").text("00:00");
        if (expireTimer) {
          clearInterval(expireTimer);
          expireTimer = null;
        }
        if (statusTimer) {
          clearInterval(statusTimer);
          statusTimer = null;
        }
        checkTransactionStatus({ clientExpired: true });
        return;
      }

      const m = Math.floor(left / 60);
      const s = left % 60;
      $("#expireTimer").text(String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0"));
    }

    tick();
    if (!statusResolved && expiresAt && expiresAt - Math.floor(Date.now() / 1000) > 0) {
      expireTimer = setInterval(tick, 1000);
    }
  }

  function fillPendingModal(data) {
    statusResolved = false;
    statusCheckInFlight = false;
    currentTx = data;
    const symbol = data.currency_symbol || (selectedCurrency && selectedCurrency.currency_symbol) || "";
    const cName = data.currency_name || (selectedCurrency && selectedCurrency.currency_name) || "";
    const nName = data.network_name || (selectedNetwork && selectedNetwork.network_name) || "";
    const nCode = data.network_code || (selectedNetwork && selectedNetwork.network_code) || "";

    let catalogCurrency = selectedCurrency;
    let catalogNetwork = selectedNetwork;
    if ((!catalogCurrency || !catalogNetwork) && currencies.length && symbol) {
      catalogCurrency =
        currencies.find(function (c) {
          return c.currency_symbol === symbol;
        }) || catalogCurrency;
      if (catalogCurrency && catalogCurrency.supported_networks && nCode) {
        catalogNetwork =
          catalogCurrency.supported_networks.find(function (n) {
            return n.network_code === nCode;
          }) || catalogNetwork;
      }
    }

    const cIcon =
      (catalogCurrency && catalogCurrency.icon) ||
      (selectedCurrency && selectedCurrency.icon) ||
      "";
    const nIcon =
      (data && data.network_icon) ||
      (catalogNetwork && catalogNetwork.icon) ||
      (selectedNetwork && selectedNetwork.icon) ||
      "";

    $("#payAssetTitle").text(symbol && cName ? symbol + " · " + cName : symbol || cName || "—");
    const safeIcon = safeImageUrl(cIcon);
    if (safeIcon) {
      $("#payCurrencyIcon").attr("src", safeIcon).removeClass("d-none");
      $("#payCurrencyFallback").addClass("d-none");
    } else {
      $("#payCurrencyIcon").addClass("d-none").attr("src", "");
      $("#payCurrencyFallback")
        .removeClass("d-none")
        .text(symbol ? symbol.charAt(0).toUpperCase() : "?");
    }

    const safeNetIcon = safeImageUrl(nIcon);
    if (safeNetIcon) {
      $("#payNetworkIcon").attr("src", safeNetIcon).removeClass("d-none");
      $("#payNetworkFallback").addClass("d-none");
    } else {
      $("#payNetworkIcon").addClass("d-none").attr("src", "");
      const netLetter = nName ? nName.charAt(0).toUpperCase() : "?";
      $("#payNetworkFallback").removeClass("d-none").text(netLetter);
    }

    $("#payNetworkName").text(nName || "—");

    if (cfg.orderNumber) {
      $("#modalOrderNum").text("#" + cfg.orderNumber);
    }

    const wallet = data.wallet_address || "";
    // Payer must send pay_amount (crypto amount from create response).
    const payAmount =
      data.pay_amount != null && data.pay_amount !== ""
        ? data.pay_amount
        : data.expected_amount;
    const expiresAt = data.expires_at || data.expired_at;

    $("#walletAddress").val(wallet);
    $("#walletAddressText").text(wallet);
    $("#totalAmount").text(formatAmount(payAmount, symbol));

    if (catalogCurrency && catalogNetwork) {
      setModalWarnHtml(symbol, nName || catalogNetwork.network_name);
    } else if (selectedCurrency && selectedNetwork) {
      setModalWarnHtml(symbol, nName || selectedNetwork.network_name);
    }

    updateWarnTexts();
    renderQr(data.qr_code || data.wallet_address);
    updateExpireCountdown(expiresAt);

    showModalView("pending");
    paymentModal.show();
  }

  function copyText(value, successMessage) {
    if (!value) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(
        function () {
          showToast(successMessage, false);
        },
        function () {
          showToast(cfg.i18n.copyFail);
        }
      );
      return;
    }
    const $tmp = $("<textarea>").val(value).appendTo("body").select();
    document.execCommand("copy");
    $tmp.remove();
    showToast(successMessage, false);
  }

  function showSuccess(data) {
    stopStatusChecks();
    const txId = data.tx_id || "";
    const url = safeHttpUrl(data.tx_id_url || "");
    const thankYou = safeHttpUrl(data.thank_you_url || "") || safeHttpUrl(cfg.thankYouUrl || "");

    $("#successTxId").text(txId || "—");
    $("#successTxUrl").text(url || "—");
    $("#copyTxIdBtn").prop("disabled", !txId).data("value", txId);
    $("#copyTxUrlBtn").prop("disabled", !url).data("value", url);
    $("#successContinueBtn").attr("href", thankYou || "#");

    if (url) {
      $("#openTxUrlBtn").attr("href", url).removeAttr("aria-disabled").css("pointer-events", "auto");
    } else {
      $("#openTxUrlBtn").attr("href", "#").attr("aria-disabled", "true").css("pointer-events", "none");
    }

    showModalView("success");
    if (!$("#paymentModal").hasClass("show")) paymentModal.show();

    if (thankYou) {
      setTimeout(function () {
        window.location.href = thankYou;
      }, 2500);
    }
  }

  function showFailed(message, review) {
    stopStatusChecks();
    $("#modalFailed h2").text(review ? cfg.i18n.reviewTitle : cfg.i18n.failedTitle);
    $("#failedRetryBtn").toggle(!review);
    $("#failedMessage").text(message || cfg.i18n.cancelled);
    showModalView("failed");
    if (!$("#paymentModal").hasClass("show")) paymentModal.show();
  }

  function handleStatusData(data) {
    if (!data || statusResolved) return;
    currentTx = Object.assign({}, currentTx || {}, data);
    const status = (data.normalized_status || data.status || "").toLowerCase();

    if (status === "manual_review") {
      showFailed(cfg.i18n.reviewMessage, true);
      return;
    }

    if (status === "confirmed" && data.is_paid === true) {
      showSuccess(data);
      return;
    }

    if (status === "cancelled" || status === "expired") {
      const msg = status === "cancelled" ? cfg.i18n.cancelled : cfg.i18n.expired;
      showFailed(msg);
    }
  }

  function checkTransactionStatus(opts) {
    if (statusResolved || statusCheckInFlight) return;
    if (!currentTx || !currentTx.tracking_code) return;

    statusCheckInFlight = true;
    const clientExpired = !!(opts && opts.clientExpired);

    $.ajax({
      url: cfg.ajaxUrl,
      method: "POST",
      data: commonData({
        action: "visualpay_status_tx",
        client_expired: clientExpired ? 1 : 0,
      }),
      success: function (res) {
        if (statusResolved) return;
        if (res && res.success && res.data) {
          handleStatusData(res.data);
          return;
        }
        if (clientExpired) {
          showFailed(cfg.i18n.expired);
        }
      },
      error: function () {
        if (clientExpired && !statusResolved) {
          showFailed(cfg.i18n.expired);
        }
      },
      complete: function () {
        statusCheckInFlight = false;
      },
    });
  }

  function startStatusPolling() {
    if (statusTimer) {
      clearInterval(statusTimer);
      statusTimer = null;
    }
    statusTimer = setInterval(function () {
      if (statusResolved) {
        clearInterval(statusTimer);
        statusTimer = null;
        return;
      }
      checkTransactionStatus();
    }, STATUS_POLL_MS);
  }

  function createTransaction(payload) {
    showLoading(true);
    $("#payBtn").prop("disabled", true);

    const safeCurrency =
      payload && payload.currency_symbol
        ? String(payload.currency_symbol).replace(/[^A-Za-z0-9._-]/g, "").slice(0, 50)
        : "";
    const safeNetwork =
      payload && payload.network_code
        ? String(payload.network_code).replace(/[^A-Za-z0-9._-]/g, "").slice(0, 50)
        : "";

    // Amount / ttl / email / order_id are server-only — never send from browser.
    const safePayload = {
      action: "visualpay_create_tx",
      currency_symbol: safeCurrency,
      network_code: safeNetwork,
    };

    $.ajax({
      url: cfg.ajaxUrl,
      method: "POST",
      data: commonData(safePayload),
      success: function (res) {
        if (!res || !res.success || !res.data) {
          showToast((res && res.data && res.data.message) || cfg.i18n.createFail);
          return;
        }
        fillPendingModal(res.data);
        startStatusPolling();
      },
      error: function (xhr) {
        let msg = cfg.i18n.createFail;
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        }
        showToast(msg);
      },
      complete: function () {
        showLoading(false);
        $("#payBtn").prop("disabled", false);
      },
    });
  }

  function openCancelConfirm() {
    if (!currentTx || !currentTx.tracking_code || statusResolved) return;
    confirmCancelModal.show();
  }

  function cancelTransaction() {
    if (!currentTx || !currentTx.tracking_code) return;

    confirmCancelModal.hide();
    $("#cancelBtn").prop("disabled", true);
    showLoading(true);

    $.ajax({
      url: cfg.ajaxUrl,
      method: "POST",
      data: commonData({ action: "visualpay_cancel_tx" }),
      success: function (res) {
        if (res && res.success) {
          showFailed(cfg.i18n.cancelled);
          return;
        }
        showToast((res && res.data && res.data.message) || cfg.i18n.cancelFail);
        $("#cancelBtn").prop("disabled", false);
      },
      error: function (xhr) {
        const msg =
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
          cfg.i18n.cancelFail;
        showToast(msg);
        $("#cancelBtn").prop("disabled", false);
      },
      complete: function () {
        showLoading(false);
      },
    });
  }

  function resetToForm() {
    stopStatusChecks();
    statusResolved = false;
    currentTx = null;
    paymentModal.hide();
    showModalView("pending");
    $("#cancelBtn").prop("disabled", false);
    updateApprox();
  }

  $(function () {
    paymentModal = new bootstrap.Modal(document.getElementById("paymentModal"), {
      backdrop: "static",
      keyboard: false,
    });
    confirmCancelModal = new bootstrap.Modal(document.getElementById("confirmCancelModal"), {
      backdrop: "static",
      keyboard: false,
    });

    $("#confirmCancelModal").on("shown.bs.modal", function () {
      $(".modal-backdrop").last().css("z-index", 1080);
    });

    $("#currencySelect").on("change", function () {
      const idx = $(this).find(":selected").data("index");
      selectedCurrency = currencies[idx] || null;
      setSelectIcon($("#currencyIcon"), selectedCurrency && selectedCurrency.icon);
      populateNetworks(selectedCurrency);
    });

    $("#networkSelect").on("change", function () {
      if (!selectedCurrency) return;
      const code = $(this).val();
      selectedNetwork =
        (selectedCurrency.supported_networks || []).find(function (n) {
          return n.network_code === code;
        }) || null;
      setSelectIcon($("#networkIcon"), selectedNetwork && selectedNetwork.icon);
      updateWarnTexts();
      updateApprox();
    });

    $("#paymentForm").on("submit", function (e) {
      e.preventDefault();

      if (!selectedCurrency || !selectedNetwork) {
        showToast(cfg.i18n.selectCurrency);
        return;
      }

      const amount = Number(cfg.amountUsd);
      const minPay = Number(selectedNetwork.minimum_pay) || 0;
      const maxPay = Number(selectedNetwork.maximum_pay) || 0;

      if (!amount || amount <= 0) {
        showToast(cfg.i18n.createFail);
        return;
      }

      if (minPay > 0 && amount < minPay) {
        showToast(cfg.i18n.minAmount + trimZeros(minPay));
        return;
      }

      if (maxPay > 0 && amount > maxPay) {
        showToast((cfg.i18n.maxAmount || "Maximum amount is $") + trimZeros(maxPay));
        return;
      }

      // Amount / email are NEVER sent from the browser — server locks them.
      createTransaction({
        currency_symbol: selectedCurrency.currency_symbol,
        network_code: selectedNetwork.network_code,
      });
    });

    $("#copyBtn").on("click", function () {
      const addr = $("#walletAddress").val() || $("#walletAddressText").text();
      if (!addr) return;
      copyText(addr, cfg.i18n.copyAddress);
    });

    $("#copyTxIdBtn").on("click", function () {
      copyText($(this).data("value"), cfg.i18n.copyTx);
    });

    $("#copyTxUrlBtn").on("click", function () {
      copyText($(this).data("value"), cfg.i18n.copyUrl);
    });

    $("#cancelBtn").on("click", openCancelConfirm);
    $("#confirmCancelBtn").on("click", cancelTransaction);
    $("#failedRetryBtn").on("click", resetToForm);

    loadCurrencies();
  });
})(jQuery);
