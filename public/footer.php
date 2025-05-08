
<footer class="footer">

<h3 class="text-ellipsis">&copy;2025 Copyright&nbsp;&nbsp;<a href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></a><?php $this->options->logoFooter(); ?></h3>
    <h4 class="text-ellipsis_copy">Powered by Typecho | Theme by <a href="https://github.com/qine233/NoLine">Noline-V20250409</a></h4>
    <span id="runtime_span" style="    color: #656d8b;
    font-weight: bold;"></span>


</footer>
<script>
  document.addEventListener('DOMContentLoaded', function () {

console.log("\n %c Noline-Customizedversion-V20250409（未提交Github） %c https://github.com/qine233/NoLine", "color:#fff;background:#3983e2;padding:5px 0;", "color:#eee;background:#f0f0f0;padding:5px 10px;");

// 初始化 NProgress
NProgress.configure({ showSpinner: false });
NProgress.start();

// 字体与图片加载完成后执行
const fontsLoaded = document.fonts.ready;
const imagesLoaded = Promise.all(
  Array.from(document.images).map(img => {
    if (img.complete) return Promise.resolve();
    return new Promise(resolve => {
      img.addEventListener('load', resolve);
      img.addEventListener('error', resolve);
    });
  })
);

Promise.all([fontsLoaded, imagesLoaded]).then(() => {
  NProgress.done();
  setTimeout(() => {
    const mainContent = document.getElementById('content-all');
    if (mainContent) {
      mainContent.classList.add('loaded');
    }
  }, 500);
});

// ✅ 菜单绑定函数封装
function bindUIEvents() {
  const oBtn3 = document.getElementById("nav_list_a_f");
  const oBox3 = document.getElementById("nav_list_a");
  const oBtn4 = document.getElementById("nav_list_a_g");
  const oBox4 = document.getElementById("nav_list_b");
  const oBtn2 = document.getElementById("sideroom-blur");
  const oBtn = document.getElementById("box_hover");
  const oBox = document.getElementById("sideroom");
  const footerBox = document.getElementById("footermobile");

  if (oBtn) {
    oBtn.onclick = function () {
      footerBox.style.cssText = "width: auto;";
      oBtn2.style.cssText = "display:block;";
      if (getComputedStyle(oBox).transform === "translateX(100%)") {
        oBox.style.cssText = "transform: translateX(0);";
      } else {
        oBox.style.cssText = "transform: translateX(100%);";
      }
    };
  }

  if (oBtn2) {
    oBtn2.onclick = function () {
      oBox.style.cssText = "transform: translateX(0);";
      oBtn2.style.cssText = "display:none;";
      footerBox.style.cssText = "width: auto;";
    };
  }

  if (oBtn4 && oBox4) {
    oBtn4.onclick = function () {
      oBox4.style.display = (getComputedStyle(oBox4).display === "none") ? "block" : "none";
    };
  }
}

// 初次加载时绑定菜单按钮
bindUIEvents();

// 滚动事件样式处理
const NcolorTWO = document.getElementById("nav_menu");
$(document).on('scroll', function () {
  if ($(document).scrollTop() <= 380) {
    NcolorTWO.style.color = "rgb(53 75 96)";
    $('.header').addClass('nobg').removeClass('hasbg');
  } else {
    NcolorTWO.style.color = "rgb(53 75 96)";
    $('.header').removeClass('nobg p1').addClass('hasbg');
  }
});


});

</script>

