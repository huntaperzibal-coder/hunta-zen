// === 📄 /adminka/js/orda_confirm.js — универсальное окно подтверждения Орды ===
// Использует уже готовые стили .custom-confirm-overlay и .custom-confirm-box из modals.css
// Работает в iframe, возвращает Promise(true/false)

function ordaConfirm(message = "Вы уверены?", okText = "Да", cancelText = "Нет") {
  return new Promise((resolve) => {
    let overlay = document.querySelector(".custom-confirm-overlay");

    // если окно ещё не создано — вставляем HTML
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.className = "custom-confirm-overlay";
      overlay.innerHTML = `
        <div class="custom-confirm-box">
          <div class="custom-confirm-message"></div>
          <div class="modal-buttons">
            <button class="btn-ok">${okText}</button>
            <button class="btn-cancel">${cancelText}</button>
          </div>
        </div>
      `;
      document.body.appendChild(overlay);
    }

    // текст сообщения
    overlay.querySelector(".custom-confirm-message").textContent = message;

    // показываем окно
    overlay.style.display = "flex";

    // обработчики
    const btnOk = overlay.querySelector(".btn-ok");
    const btnCancel = overlay.querySelector(".btn-cancel");

    const close = (result) => {
      overlay.style.display = "none";
      resolve(result);
    };

    btnOk.onclick = () => close(true);
    btnCancel.onclick = () => close(false);
  });
}

window.ordaConfirm = ordaConfirm;
