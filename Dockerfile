# 使用官方 PHP Apache 镜像
FROM php:8.2-apache

# 启用 Apache rewrite 模块 (虽然这个简单的项目不一定用得到，但加上是好习惯)
RUN a2enmod rewrite

# 安装必要的 PHP 扩展
# DOM 和 XML 解析通常包含在核心中，但 cURL 有时需要显式安装
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libxml2-dev \
    && docker-php-ext-install curl dom xml

# 设置工作目录
WORKDIR /var/www/html

# 复制源代码到容器
COPY src/ /var/www/html/

# 设置权限 (确保 Apache 可以读取)
RUN chown -R www-data:www-data /var/www/html

# 暴露端口
EXPOSE 80
