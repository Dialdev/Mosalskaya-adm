$(function() {
	/*-------feedback------*/
	$('.header-phone__order').click(function(){
		$('.feedback_call').fadeIn();
		$('.blackblock_call').fadeIn();
	});
	$('.button__close, .blackblock').click(function(){
		$('.feedback').fadeOut();
		$('.blackblock').fadeOut();
	});
	
	/*-------up-button----*/
	$(window).scroll(function() {
		if($(this).scrollTop() != 0) {
			$('.up').fadeIn();
		} else {
			$('.up').fadeOut();
		}
		});
		$('.up').click(function() {
		$('body,html').animate({scrollTop:0},800);
	});
	
	/*-----nav-menu-title-fixed-----*/
	$('.nav-menu__link').each(function(index) {
		//$(this).css({'top': '50%', 'margin-top':-$(this).outerHeight()/2});
		$(this).parent().css({'padding':0});
		$(this).css({'padding-top':(46-$(this).outerHeight())/2});
		outer=$(this).outerHeight();
		console.log(outer);
		
		outer= 46-$(this).outerHeight();
		console.log(outer);
		
	});

	//accordeon//
	$('.accordeon__h3').click(function(){		
		if ($(this).next().hasClass("open")){
				$(this).next().stop().slideUp().removeClass("open");
			}
			else {
				$('.accordeon__content').slideUp().removeClass("open");
				$(this).next().slideDown().addClass("open");
			}
		
	});

	/*-------other----*/
	$(".fb").fancybox({padding : 0, helpers:{overlay: {locked: false}}});
	//$('form input[type="text"]').textPlaceholder();	
});