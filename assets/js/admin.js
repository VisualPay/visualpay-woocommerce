(function ($) {
  "use strict";

  if (typeof VisualPayAdmin === "undefined") {
    return;
  }

  const TOAST_DURATION_MS = 5000;

  function openOrderModal() {
    $("#visualpay-order-modal").addClass("is-open").attr("aria-hidden", "false");
  }

  function closeOrderModal() {
    $("#visualpay-order-modal").removeClass("is-open").attr("aria-hidden", "true");
  }

  let toastTimer = null;

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

  function renderTrustedHtml(value) {
    return escapeHtml(value || "—");
  }

  function formatUsdAmount(value) {
    if (value === null || value === undefined || value === "" || value === "—") {
      return "—";
    }

    const num = Number(String(value).replace(/,/g, ""));
    if (!isFinite(num)) {
      return escapeHtml(String(value));
    }

    return (
      "$" +
      num.toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })
    );
  }

  function renderCopyableField(value, linkUrl) {
    const text = value ? String(value) : "";
    if (!text) {
      return "—";
    }

    let inner = escapeHtml(text);
    const safeUrl = safeHttpUrl(linkUrl || "");
    if (safeUrl) {
      inner =
        '<a href="' +
        escapeHtml(safeUrl) +
        '" target="_blank" rel="noopener noreferrer">' +
        escapeHtml(text) +
        "</a>";
    }

    return (
      '<span class="visualpay-transactions__copy-cell visualpay-modal__copy-cell">' +
      inner +
      '<button type="button" class="visualpay-transactions__copy-btn" data-copy="' +
      escapeHtml(text) +
      '" aria-label="Copy"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button></span>'
    );
  }

  function syncGatewayStatus($input) {
    if (!$input || !$input.length) {
      return;
    }

    const isActive = $input.is(":checked");
    const $pill = $("#visualpay-gateway-status-pill");
    const $label = $("#visualpay-gateway-status-label");

    $pill
      .toggleClass("is-active", isActive)
      .toggleClass("is-inactive", !isActive);

    const i18n = (window.VisualPayAdmin && window.VisualPayAdmin.i18n) || {};
    $label.text(
      isActive
        ? i18n.statusActive || "Plugin status : Active"
        : i18n.statusInactive || "Plugin status : Inactive"
    );
  }

  function showToast(message, isError) {
    const $toast = $("#visualpay-toast");
    if (!$toast.length) {
      return;
    }

    $toast
      .removeClass("is-success is-error")
      .addClass(isError ? "is-error" : "is-success")
      .text(message)
      .prop("hidden", false)
      .addClass("is-visible");

    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () {
      $toast.removeClass("is-visible");
      window.setTimeout(function () {
        $toast.prop("hidden", true);
      }, 250);
    }, TOAST_DURATION_MS);
  }

  function renderModal(data) {
    const order = data.order || {};
    const tx = data.transaction || {};
    let html = "";

    html += "<h3>Order #" + escapeHtml(order.number || order.id || "") + "</h3>";
    html += "<table><tbody>";
    html += "<tr><th>Status</th><td>" + escapeHtml(order.status || "—") + "</td></tr>";
    html += "<tr><th>Total</th><td>" + renderTrustedHtml(order.total) + "</td></tr>";
    html += "<tr><th>Payment method</th><td>" + escapeHtml(order.payment_method || "—") + "</td></tr>";
    html += "<tr><th>Date</th><td>" + escapeHtml(order.date || "—") + "</td></tr>";
    html += "<tr><th>Customer</th><td>" + escapeHtml(order.billing_name || "—") + "<br>" + escapeHtml(order.billing_email || "") + "</td></tr>";
    html += "</tbody></table>";

    if (order.items && order.items.length) {
      html += "<h3>Items</h3><table><thead><tr><th>Product</th><th>Qty</th><th>Total</th></tr></thead><tbody>";
      order.items.forEach(function (item) {
        html +=
          "<tr><td>" +
          escapeHtml(item.name || "") +
          "</td><td>" +
          escapeHtml(item.qty || "") +
          "</td><td>" +
          renderTrustedHtml(item.total) +
          "</td></tr>";
      });
      html += "</tbody></table>";
    }

    if (tx && (tx.tracking_code || tx.tx_id)) {
      html += "<h3>VisualPay</h3><table><tbody>";
      html += "<tr><th>Status</th><td>" + escapeHtml(tx.status || "—") + "</td></tr>";
      html += "<tr><th>USD amount</th><td>" + formatUsdAmount(tx.usdt_amount) + "</td></tr>";
      html += "<tr><th>Paid amount</th><td>" + escapeHtml(tx.paid_amount || "—") + "</td></tr>";
      html +=
        "<tr><th>Currency</th><td>" +
        escapeHtml(tx.currency_name || tx.currency_symbol || "—") +
        "</td></tr>";
      html += "<tr><th>Network</th><td>" + escapeHtml(tx.network_name || "—") + "</td></tr>";
      html += "<tr><th>Tracking</th><td>" + renderCopyableField(tx.tracking_code) + "</td></tr>";
      html +=
        "<tr><th>TX ID</th><td>" +
        renderCopyableField(tx.tx_id, safeHttpUrl(tx.tx_id_url || "")) +
        "</td></tr>";
      html += "</tbody></table>";
    }

    const editUrl = safeHttpUrl(order.edit_url || "");
    if (editUrl) {
      html +=
        '<div class="visualpay-modal__actions"><a class="visualpay-modal__action-btn" href="' +
        escapeHtml(editUrl) +
        '">Open in WooCommerce</a></div>';
    }

    $("#visualpay-modal-body").html(html);
  }

  $(document).on("click", ".visualpay-transactions tbody tr", function (e) {
    if ($(e.target).closest("a, button, input, select").length && !$(e.target).closest(".visualpay-open-order").length) {
      return;
    }
    const $link = $(this).find(".visualpay-open-order").first();
    if ($link.length) {
      e.preventDefault();
      $link.trigger("click.visualpay");
    }
  });

  $(document).on("click.visualpay", ".visualpay-open-order", function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $btn = $(this);
    const txRowId = $btn.data("tx-row-id");
    const orderId = $btn.data("order-id");

    $("#visualpay-modal-body").html("<p>" + escapeHtml(VisualPayAdmin.i18n.loading) + "</p>");
    openOrderModal();

    $.ajax({
      url: VisualPayAdmin.ajaxUrl,
      method: "POST",
      data: {
        action: "visualpay_admin_order_modal",
        nonce: VisualPayAdmin.nonce,
        tx_row_id: txRowId,
        order_id: orderId,
      },
      success: function (res) {
        if (!res || !res.success) {
          $("#visualpay-modal-body").html("<p>" + escapeHtml(VisualPayAdmin.i18n.error) + "</p>");
          return;
        }
        renderModal(res.data || {});
      },
      error: function () {
        $("#visualpay-modal-body").html("<p>" + escapeHtml(VisualPayAdmin.i18n.error) + "</p>");
      },
    });
  });

  $(document).on("click", "#visualpay-order-modal [data-close]", function () {
    closeOrderModal();
  });

  $(document).on("keydown", function (e) {
    if (e.key === "Escape") {
      closeOrderModal();
    }
  });

  $(document).on("click", ".visualpay-transactions__copy-btn", function (e) {
    e.preventDefault();
    e.stopPropagation();

    const $btn = $(this);
    const value = String($btn.data("copy") || "");

    if (!value) {
      return;
    }

    function markCopied() {
      $btn.addClass("is-copied");
      showToast(VisualPayAdmin.i18n.copied || "Copied to clipboard.", false);
      window.setTimeout(function () {
        $btn.removeClass("is-copied");
      }, 1500);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(markCopied).catch(function () {
        showToast(VisualPayAdmin.i18n.copyFailed || "Copy failed.", true);
      });
      return;
    }

    const $temp = $("<textarea>").val(value).appendTo("body").select();
    try {
      document.execCommand("copy");
      markCopied();
    } catch (err) {
      showToast(VisualPayAdmin.i18n.copyFailed || "Copy failed.", true);
    }
    $temp.remove();
  });

  $(document).on("click", ".visualpay-password-toggle", function () {
    const $btn = $(this);
    const $input = $btn.siblings("input").first();
    if (!$input.length) {
      return;
    }

    const isHidden = $input.attr("type") === "password";
    $input.attr("type", isHidden ? "text" : "password");
    $btn.attr("aria-pressed", isHidden ? "true" : "false");
    $btn.attr("aria-label", isHidden ? $btn.data("label-hide") : $btn.data("label-show"));
    $btn.find(".dashicons")
      .toggleClass("dashicons-visibility", !isHidden)
      .toggleClass("dashicons-hidden", isHidden);
  });

  $(document).on("change", "#visualpay-gateway-enabled", function () {
    const $input = $(this);
    const enabled = $input.is(":checked") ? "yes" : "no";
    const previous = $input.data("current") || enabled;

    $input.prop("disabled", true);
    $("#visualpay-gateway-status-pill").addClass("is-loading");
    syncGatewayStatus($input);

    $.ajax({
      url: VisualPayAdmin.ajaxUrl,
      method: "POST",
      data: {
        action: "visualpay_admin_toggle_gateway",
        nonce: VisualPayAdmin.nonce,
        enabled: enabled,
      },
      success: function (res) {
        if (res && res.success) {
          $input.data("current", enabled);
          syncGatewayStatus($input);
          showToast((res.data && res.data.message) || VisualPayAdmin.i18n.statusUpdated, false);
          return;
        }

        $input.prop("checked", previous === "yes");
        syncGatewayStatus($input);
        showToast(
          (res && res.data && res.data.message) || VisualPayAdmin.i18n.statusFailed,
          true
        );
      },
      error: function () {
        $input.prop("checked", previous === "yes");
        syncGatewayStatus($input);
        showToast(VisualPayAdmin.i18n.statusFailed, true);
      },
      complete: function () {
        $input.prop("disabled", false);
        $("#visualpay-gateway-status-pill").removeClass("is-loading");
      },
    });
  });

  $(document).on("click", "#visualpay-test-api-key", function () {
    const $btn = $(this);
    const apiKey = $("#visualpay_api_key").val();

    $btn.prop("disabled", true).text(VisualPayAdmin.i18n.testLoading);

    $.ajax({
      url: VisualPayAdmin.ajaxUrl,
      method: "POST",
      data: {
        action: "visualpay_admin_test_api_key",
        nonce: VisualPayAdmin.nonce,
        api_key: apiKey,
      },
      success: function (res) {
        if (res && res.success) {
          showToast(
            (res.data && res.data.message) || VisualPayAdmin.i18n.testSuccessMsg,
            false
          );
          return;
        }

        showToast(
          (res && res.data && res.data.message) || VisualPayAdmin.i18n.testFailedMsg,
          true
        );
      },
      error: function () {
        showToast(VisualPayAdmin.i18n.testFailedMsg, true);
      },
      complete: function () {
        $btn.prop("disabled", false).text(VisualPayAdmin.i18n.testButton);
      },
    });
  });

  function initCoinCarousel() {
    const root = document.querySelector(".visualpay-dashboard__coin-carousel");
    if (!root) {
      return;
    }

    const viewport = root.querySelector(".visualpay-dashboard__coin-viewport");
    const track = root.querySelector(".visualpay-dashboard__coin-track");
    const prevBtn = root.querySelector(".visualpay-dashboard__coin-nav.is-prev");
    const nextBtn = root.querySelector(".visualpay-dashboard__coin-nav.is-next");
    const items = root.querySelectorAll(".visualpay-dashboard__coin");
    if (!viewport || !track || !prevBtn || !nextBtn || !items.length) {
      return;
    }

    const gap = 10;
    let index = 0;

    function getVisibleCount() {
      if (window.matchMedia("(max-width: 560px)").matches) {
        return 1;
      }
      if (window.matchMedia("(max-width: 900px)").matches) {
        return 3;
      }
      const configured = parseInt(root.getAttribute("data-visible") || "5", 10);
      return configured > 0 ? configured : 5;
    }

    function maxIndex() {
      return Math.max(0, items.length - getVisibleCount());
    }

    function update() {
      const visible = getVisibleCount();
      const scrollable = items.length > visible;
      root.classList.toggle("is-scrollable", scrollable);

      index = Math.min(Math.max(0, index), maxIndex());

      const viewportWidth = viewport.getBoundingClientRect().width;
      const itemWidth = Math.max(0, (viewportWidth - gap * (visible - 1)) / visible);

      Array.prototype.forEach.call(items, function (el) {
        el.style.flex = "0 0 " + itemWidth + "px";
        el.style.width = itemWidth + "px";
        el.style.maxWidth = itemWidth + "px";
      });

      track.style.transform = "translateX(-" + (index * (itemWidth + gap)).toFixed(2) + "px)";

      prevBtn.disabled = !scrollable || index <= 0;
      nextBtn.disabled = !scrollable || index >= maxIndex();
    }

    prevBtn.addEventListener("click", function () {
      index = Math.max(0, index - 1);
      update();
    });

    nextBtn.addEventListener("click", function () {
      index = Math.min(maxIndex(), index + 1);
      update();
    });

    $(window).on("resize.visualpayCoinCarousel", function () {
      update();
    });

    update();
  }

  function initOverviewDashboard() {
    const $root = $(".visualpay-overview");
    if (!$root.length) {
      return;
    }

    initCoinCarousel();

    const dataEl = document.getElementById("visualpay-dashboard-data");
    const chartSvg = document.getElementById("visualpay-chart-svg");
    if (!dataEl || !chartSvg) {
      return;
    }

    let points = [];
    try {
      points = JSON.parse(dataEl.textContent || "{}").chartPoints || [];
    } catch (err) {
      points = [];
    }

    if (!points.length) {
      points = [0];
    }

    const W = 1000;
    const H = 220;
    const padT = 14;
    const padB = 26;
    const max = Math.max.apply(null, points.concat([1]));
    const min = 0;
    const stepX = points.length > 1 ? W / (points.length - 1) : W;

    function yFor(v) {
      return H - padB - ((v - min) / (max - min)) * (H - padT - padB);
    }

    let path = "";
    points.forEach(function (v, i) {
      const x = i * stepX;
      const y = yFor(v);
      path += (i === 0 ? "M" : "L") + x.toFixed(1) + " " + y.toFixed(1) + " ";
    });

    const areaPath =
      path +
      "L " +
      ((points.length - 1) * stepX).toFixed(1) +
      " " +
      (H - padB) +
      " L 0 " +
      (H - padB) +
      " Z";

    const gridLines = [0, 0.25, 0.5, 0.75, 1]
      .map(function (f) {
        const y = padT + f * (H - padT - padB);
        return '<line class="visualpay-dashboard__grid-line" x1="0" y1="' + y + '" x2="' + W + '" y2="' + y + '"/>';
      })
      .join("");

    const lastX = (points.length - 1) * stepX;
    const lastY = yFor(points[points.length - 1]);

    chartSvg.innerHTML =
      '<defs><linearGradient id="visualpayFillGrad" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0%" stop-color="#f5a623" stop-opacity="0.45"/>' +
      '<stop offset="100%" stop-color="#f5a623" stop-opacity="0"/>' +
      "</linearGradient></defs>" +
      gridLines +
      '<path d="' +
      areaPath +
      '" fill="url(#visualpayFillGrad)" stroke="none"/>' +
      '<path d="' +
      path +
      '" fill="none" stroke="#f5a623" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>' +
      '<circle cx="' +
      lastX +
      '" cy="' +
      lastY +
      '" r="4.5" fill="#f5a623">' +
      '<animate attributeName="r" values="4.5;7;4.5" dur="2s" repeatCount="indefinite"/>' +
      '<animate attributeName="opacity" values="1;0.5;1" dur="2s" repeatCount="indefinite"/>' +
      "</circle>";
  }

  $(initOverviewDashboard);

  $(function () {
    syncGatewayStatus($("#visualpay-gateway-enabled"));

    const $settingsPage = $(".visualpay-settings-page");
    const flashSuccess = $settingsPage.attr("data-visualpay-toast-success");
    if (flashSuccess) {
      showToast(flashSuccess || (VisualPayAdmin.i18n && VisualPayAdmin.i18n.settingsSaved) || "Settings saved.", false);
      $settingsPage.removeAttr("data-visualpay-toast-success");
    }

    fitVisualPayLockedShell();
    requestAnimationFrame(function () {
      fitVisualPayLockedShell();
      requestAnimationFrame(fitVisualPayLockedShell);
    });
    $(window).on("resize", fitVisualPayLockedShell);
  });

  function isVisualPayDesktop() {
    return window.matchMedia("(min-width: 783px)").matches;
  }

  function clearShellSizing(shell, wrap) {
    if (shell) {
      shell.style.height = "";
      shell.style.minHeight = "";
      shell.style.maxHeight = "";
    }
    if (wrap) {
      wrap.style.height = "";
      wrap.style.maxHeight = "";
    }
  }

  function fitShellToFooter(shell, wrap) {
    const footer = document.getElementById("wpfooter");
    const shellTop = shell.getBoundingClientRect().top;
    let endY = window.innerHeight;

    if (footer) {
      const footerTop = footer.getBoundingClientRect().top;
      if (footerTop > shellTop && footerTop <= window.innerHeight + 1) {
        endY = footerTop;
      }
    }

    const h = Math.max(0, Math.floor(endY - shellTop));
    if (h <= 0) {
      return;
    }

    if (wrap) {
      wrap.style.height = h + "px";
      wrap.style.maxHeight = h + "px";
    }
    shell.style.height = h + "px";
    shell.style.minHeight = h + "px";
    shell.style.maxHeight = h + "px";
  }

  function fitVisualPayLockedShell() {
    const isDesktop = isVisualPayDesktop();

    // Settings
    const settingsShell = document.querySelector(".visualpay-settings-shell");
    const settingsWrap = document.querySelector(".visualpay-settings-page.wrap");
    if (settingsShell && document.body.classList.contains("visualpay-admin-settings-screen")) {
      if (!isDesktop) {
        document.documentElement.classList.remove("visualpay-settings-no-scroll");
        document.body.classList.remove("visualpay-settings-no-scroll");
        clearShellSizing(settingsShell, settingsWrap);
      } else {
        document.documentElement.classList.add("visualpay-settings-no-scroll");
        document.body.classList.add("visualpay-settings-no-scroll");
        fitShellToFooter(settingsShell, settingsWrap);
      }
    }

    // Transactions
    const txShell = document.querySelector(".visualpay-transactions-shell, .visualpay-transactions-page .visualpay-dashboard");
    const txWrap = document.querySelector(".visualpay-transactions-page.wrap");
    if (txShell && document.body.classList.contains("visualpay-admin-transactions-screen")) {
      if (!isDesktop) {
        document.documentElement.classList.remove("visualpay-transactions-no-scroll");
        document.body.classList.remove("visualpay-transactions-no-scroll");
        clearShellSizing(txShell, txWrap);
      } else {
        document.documentElement.classList.add("visualpay-transactions-no-scroll");
        document.body.classList.add("visualpay-transactions-no-scroll");
        fitShellToFooter(txShell, txWrap);
      }
    }
  }
})(jQuery);
