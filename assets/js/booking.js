(function () {
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  function i18n(key, fallback) {
    if (typeof MCEMS_BOOKING !== 'undefined' && MCEMS_BOOKING.i18n && MCEMS_BOOKING.i18n[key]) {
      return MCEMS_BOOKING.i18n[key];
    }
    return fallback || key;
  }

  function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
    return fetch(MCEMS_BOOKING.ajaxUrl, { method: "POST", credentials: "same-origin", body: fd })
      .then(r => r.json());
  }

  // Prenota: carica sessioni per data
  $all("[data-mcems-booking]").forEach(function (wrap) {
    const dateInput = $(".mcems-date", wrap);
    const sessBox   = $(".mcems-sessions", wrap);
    const msgBox    = $(".mcems-msg", wrap);

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
      ul.className = "mcems-session-list";
      items.forEach(function (s) {
        const li = document.createElement("li");
        li.innerHTML =
          `<label>
            <input type="radio" name="mcems_session_id" value="${s.id}">
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
        post("mcems_get_sessions_by_date", {
          nonce: MCEMS_BOOKING.nonce,
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

    const bookBtn = $(".mcems-book-btn", wrap);
    if (bookBtn) {
      bookBtn.addEventListener("click", function (e) {
        e.preventDefault();
        setMsg("");

        const checked = $("input[name='mcems_session_id']:checked", wrap);
        if (!checked) {
          setMsg("Select an exam session before booking.", true);
          return;
        }

        bookBtn.disabled = true;
        post("mcems_confirm_booking", {
          nonce: MCEMS_BOOKING.nonce,
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
  $all("[data-mcems-manage]").forEach(function (wrap) {
    const cancelBtn = $(".mcems-cancel-btn", wrap);
    const msgBox = $(".mcems-msg", wrap);

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
        post("mcems_cancel_booking", { nonce: MCEMS_BOOKING.cancelNonce || MCEMS_BOOKING.nonce })
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
