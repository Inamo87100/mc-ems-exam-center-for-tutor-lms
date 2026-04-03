(function () {
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  function i18n(key, fallback) {
    if (typeof MCEMEXCE_BOOKING !== 'undefined' && MCEMEXCE_BOOKING.i18n && MCEMEXCE_BOOKING.i18n[key]) {
      return MCEMEXCE_BOOKING.i18n[key];
    }
    return fallback || key;
  }

  function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
    return fetch(MCEMEXCE_BOOKING.ajaxUrl, { method: "POST", credentials: "same-origin", body: fd })
      .then(r => r.json());
  }

  // Prenota: carica sessioni per data
  $all("[data-mcemexce-booking]").forEach(function (wrap) {
    const dateInput = $(".mcemexce-date", wrap);
    const sessBox   = $(".mcemexce-sessions", wrap);
    const msgBox    = $(".mcemexce-msg", wrap);

    function setMsg(t, isErr) {
      msgBox.textContent = t || "";
      msgBox.style.color = isErr ? "crimson" : "";
    }

    function renderSessions(items) {
      sessBox.innerHTML = "";
      if (!items || !items.length) {
        sessBox.innerHTML = "<em>No sessions available for the selected date.</em>";
        return;
      }

      const ul = document.createElement("ul");
      ul.className = "mcemexce-session-list";
      items.forEach(function (s) {
        const li = document.createElement("li");
        li.innerHTML =
          `<label>
            <input type="radio" name="mcemexce_session_id" value="${s.id}">
            <strong>${s.time}</strong> ${s.label}
          </label>`;
        ul.appendChild(li);
      });
      sessBox.appendChild(ul);
    }

    if (dateInput) {
      dateInput.addEventListener("change", function () {
        setMsg("");
        renderSessions([]);
        post("mcemexce_get_sessions_by_date", {
          nonce: MCEMEXCE_BOOKING.nonce,
          date: dateInput.value
        }).then(function (res) {
          if (!res || !res.success) {
            setMsg((res && res.data && res.data.message) ? res.data.message : i18n('errorLoadSessions', 'Error loading sessions.'), true);
            return;
          }
          renderSessions(res.data.sessions);
        });
      });
    }

    const bookBtn = $(".mcemexce-book-btn", wrap);
    if (bookBtn) {
      bookBtn.addEventListener("click", function (e) {
        e.preventDefault();
        setMsg("");

        const checked = $("input[name='mcemexce_session_id']:checked", wrap);
        if (!checked) {
          setMsg("Select an exam session before booking.", true);
          return;
        }

        bookBtn.disabled = true;
        post("mcemexce_confirm_booking", {
          nonce: MCEMEXCE_BOOKING.nonce,
          session_id: checked.value
        }).then(function (res) {
          bookBtn.disabled = false;
          if (!res || !res.success) {
            setMsg((res && res.data && res.data.message) ? res.data.message : i18n('bookingFailed', 'Exam booking failed.'), true);
            return;
          }
          setMsg(i18n('bookingConfirmed', 'Exam booking confirmed!'));
          window.location.reload();
        });
      });
    }
  });

  // Gestisci: cancella
  $all("[data-mcemexce-manage]").forEach(function (wrap) {
    const cancelBtn = $(".mcemexce-cancel-btn", wrap);
    const msgBox = $(".mcemexce-msg", wrap);

    function setMsg(t, isErr) {
      msgBox.textContent = t || "";
      msgBox.style.color = isErr ? "crimson" : "";
    }

    if (cancelBtn) {
      cancelBtn.addEventListener("click", function (e) {
        e.preventDefault();
        setMsg("");

        if (!confirm("Do you want to cancel the booking?")) return;

        cancelBtn.disabled = true;
        post("mcemexce_cancel_booking", { nonce: MCEMEXCE_BOOKING.cancelNonce || MCEMEXCE_BOOKING.nonce })
          .then(function (res) {
            cancelBtn.disabled = false;
            if (!res || !res.success) {
              setMsg((res && res.data && res.data.message) ? res.data.message : i18n('cancellationFailed', 'Cancellation failed.'), true);
              return;
            }
            setMsg(i18n('bookingCancelled', 'Exam booking cancelled.'));
            window.location.reload();
          });
      });
    }
  });
})();
