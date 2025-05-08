
//树莓派LINUX_温度查询 执行PHP文件时须带root权限（未验证），PHP版本8.4
<?php
$temp = exec('vcgencmd measure_temp');
?>
//前端输出，对结果处理
<div id=RPI5_CpuTemp class="" >
   <span> 
    <?php echo substr($temp, 6, 7);  ?> // 过滤结果多余字符（TEMP=），提取两位数温度
</span>
</div>

