<?php

/**
 * 友链页面
 *
 * @package custom
 *
 **/

?>
<?php $this->need('public/header.php'); ?>

<div class="content-all"><?php $this->need('sidebar.php'); ?>


<div id="pjax-container">
        <div class="typecho-user-text">
             <div class="content-list-post border-wid">
                 <!-- 下面text部分 -->
		<div class="typecho-text text-tream " >


        </div>
                       <h1 class="post-title" itemprop="name headline">「&nbsp&nbsp<?php $this->title() ?>&nbsp&nbsp」</h1>
       <div class="content-text-2 "  id="write">

           <ul id="flinks">

        <?php $this->content(); ?>


    </div>

    </div> <?php $this->need('comments.php'); ?>
    </div>
    <script>
        (function(){
            let a =document.getElementById("flinks");
            if(a){
                let ns = a.querySelectorAll("li");
                let nsl = ns.length;
                let str ='<div class="post-lists"><div class="post-lists-body" id ="flinksH">';
                let bgid = 0;
                const bgs =["bg-white"];
                for(let i = 0;i<=nsl-4;i+=4){
                    bgid = Math.floor(Math.random() * 6);
                    str += (`<div class="post-list-item"><div class="post-list-item-container "><div class="item-label ${bgs[bgid]}"><div class="item-title"><a target="_blank" href="${ns[i+1].innerText}">${ns[i].innerText}</a></div><div class="item-meta clearfix"><div class="item-meta-ico bg-ico-book"style="background: url(${ns[i+2].innerText}) no-repeat;background-size: 45px auto;"></div><div class="item-meta-date">${ns[i+3].innerText}</div></div></div></div></div>`);
                }
                str+='</div></div><style></style>';
                let n1 = document.createElement("div");
                n1.innerHTML = str;
                a.parentNode.insertBefore(n1,a);
                a.style="display: none;";
            }else{
                console.log('No such id "flinksH"');
            }
        }())
    </script>

    <script src="https://cdn.jsdelivr.net/npm/vditor/dist/index.min.js"></script>
              <script type="text/javascript">
                     var image = new Viewer(document.getElementById('write'),{
                                         url: 'src'
                                     });
                     </script></div></div>
 <?php $this->need('sidebar-right.php'); ?>
           </div>
          
</div>
</div>
</div></div>
<?php $this->need('public/footer.php'); ?>



</body>