/* Prepare Telegram's video cover locally; no separate service or paid request. */
window.daoVideoPreview = async source => {
  const video = document.createElement('video');
  video.muted = true; video.playsInline = true; video.preload = 'auto';
  let timer;
  try {
    await new Promise((resolve, reject) => {
      timer = setTimeout(() => reject(Error('Не удалось прочитать видео для обложки.')), 30000);
      video.onerror = () => reject(Error('Браузер не смог открыть видео. Нужен MP4 H.264.'));
      video.onloadeddata = () => {
        if (!video.videoWidth || !video.videoHeight) return;
        if (video.duration > 1) { video.onseeked = resolve; video.currentTime = Math.min(1, video.duration / 2); }
        else resolve();
      };
      video.src = source; video.load();
    });
    const canvas = document.createElement('canvas');
    const scale = Math.min(1, 320 / Math.max(video.videoWidth, video.videoHeight));
    canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
    canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
    if (!blob || blob.size >= 200000) throw Error('Не удалось подготовить обложку видео.');
    return blob;
  } finally { clearTimeout(timer); video.removeAttribute('src'); video.load(); }
};
