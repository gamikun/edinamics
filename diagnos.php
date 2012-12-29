<?php

require_once 'modelos/diagno.php';
require_once 'modelos/diagno_cat.php';
require_once 'modelos/diagno_preg.php';
require_once 'modelos/objeto.php';

class Diagnos_Controlador extends Controlador
{
	public $cates;
	public $cate;
	public $diagno;
	public $objeto;
	public $secc;  // lista de secciones
	public $usecc; // una sección
	public $nsecc; // siguiente sección
	public $asecc; // sección anterior
	public $preg;
	public $modulo; // para la validación de permisos
	public $rol; // el permiso en sí
	public $carpeta;
	public $conte;
	public $contes;
	public $pregs; // lista de preguntas
	public $contepregs; // respuestas
	public $usuario;
	public $invis; // lista de invitados
	
	/**
	 * Muestra la lista de categorías de preguntas
	 * de diagnóstico.
	 */
	function cates() {
		$this->vista->llenar('diagnos/cate-lista.html',array(
			'cates'  => $this->_get_cates(),
			'titulo' => 'Categorías de Diagnóstico'
		));
	}
	
	/**
	 * Muestra la introducción al diagnóstico.
	 */
	function intro() {
		$g =& $this->get;
		$agotado = false;
		$tp = array('d'=>0,'h'=>0,'m'=>0,'s'=>0);
		// determinar si se va a trabajar en modo invitado o usuario
		$modo = (strlen($g->tk) > 10 and ($invi = $this->_get_invitado_por_tk($g->tk)))
			? 'inv' : 'reg';
		if( $this->_get_objeto($g->id) and $this->_get_carpeta($this->objeto['id_carpeta'])
		and $this->_get_obj_diagno($this->objeto['id']) and $this->_get_carpeta($this->objeto['id_carpeta'])
		and ($this->rol > 0 or es_admin() or $invi) ) {
			
			if( $modo == 'reg' and !$this->_get_conte_por_u($this->sesion->uid) )
				$agrconte = array('campo'=>'id_usuario','valor'=>$this->sesion->uid);
			elseif( $modo == 'inv' and !$this->_get_conte_por_i($invi['id']) )
				$agrconte = array('campo'=>'id_invitado','valor'=>$invi['id']);
				
			if( isset($agrconte) ) {
				$conid = $this->db()->ins('objetos_diagnos_contes',array(
					$agrconte['campo']     => $agrconte['valor'],
					'id_diagnostico' => $this->diagno['id'] ));
				$this->_get_conte($conid);
			}
			$tfin = strtotime($this->conte['fecha_fin']);
			if( $tfin != 0 and time() > $tfin ) {
				// tiempo agotado, ya no puedes continuar
				$agotado = true;
			}
			
			if( !$agotado ) {
				if( $this->conte['estado'] == 0 )
					$tt = $this->diagno['duracion'];
				else
					$tt = strtotime($this->conte['fecha_fin']) - time();
				$tt = $this->diagno['duracion'];
				$tp['h'] = floor($tt / 60 / 60);
				$tt -= $tp['h'] * 60 * 60;
				$tp['m'] = floor($tt / 60);
				$tt -= $tp['m'] * 60;
				$tp['s'] = $tt;
			}
			$this->vista->llenar('diagnos/diagno-intro.html',array(
				'modo'   => $modo,
				'titulo' => $this->objeto['nombre'],
				'conte'  => $this->conte,
				'objeto' => $this->objeto,
				'agotado'=> $agotado,
				'dura'   => $tp // arreglo de tiempo
			));
		} else {
			$this->vista->imprimir('No existe');
		}
	}

	/**
	 * Muestra la lista de contestaciones de un diagnóstico.
	 * De las cuales se muestra el usuario, y se puede reiniciar,
	 * editar o hacer otro tipo de modificaciones y acciones.
	 */
	function contes() {
		$perm_coord = false;
		$g =& $this->get;
		if( $this->_get_objeto($g->id) and $this->_get_obj_diagno($g->id)
		and $this->_get_carpeta($this->objeto['id_carpeta'])
		and ($this->rol == 2 or $this->rol == 3 or es_admin() or ($perm_coord = $this->_perm_coord($this->objeto['id']))  )  ) {
			$this->_get_contes($this->diagno['id']);
			// calcular el tiempo en formato 00:00:00
			$tt = $this->diagno['duracion'];
			$hr = floor($tt/60/60);
			$tt -= ($hr*60*60);
			$mn = floor($tt/60);
			$tt -= ($mn*60);
			$this->vista->llenar('diagnos/contes.html',array(
				'titulo'     => 'Diagnósticos Respondidos',
				'dura'       => array('h'=>$hr,'m'=>$mn),
				'usuario'    => $this->_get_usuario($this->conte['id_usuario']),
				'perm_coord' => $perm_coord
			));
		} else {
			$this->vista->imprimir('No existe el objeto');
		}
	}
	
	/**
	 * Elimina un registro del tipo especificado.
	 * El parámetro llega por POST, y se llama "que".
	 * Este puede ser:
	 *   - conte: Contestación de usuario.
	 *   - invi: Invitación o usuario externo.
	 * Si no se recibe un tipo válido, devuelve NO_TIPO.
	 */
	function eliminar() {
		$p =& $this->post;
		if( $p->que == 'conte' ) {
			if( $this->_get_conte($p->id) and $this->_get_diagno($this->conte['id_diagnostico'])
			and $this->_get_objeto($this->diagno['id_objeto']) and $this->_get_carpeta($this->objeto['id_carpeta'])  ) {
				$this->db()->un('objetos_diagnos_contes')->eliminar()
					->si('id = ?', $this->conte['id'])
					->ejecutar();
				// borrar también las respuestas
				$this->db()->de('objetos_diagnos_contes_resp')->eliminar()
					->si('id_contes = ?', $this->conte['id'])
					->ejecutar();
				$s = 'OK';
			} else {
				$s = 'NO_CONTE';
			}
		} elseif( $p->que == 'invi' ) {
			if( ($inv = $this->_get_invitado($p->id))
			and $this->_get_diagno($inv['id_diagnostico']) and $this->_get_objeto($this->diagno['id_objeto'])
			and $this->_get_carpeta($this->objeto['id_carpeta'])
			and ($this->rol == 2 or $this->rol == 3 or $this->sesion->usuario->posicion == 4) ) {
				$this->db()->un('objetos_diagnos_invitados')->eliminar()
					->si('id = ?', $inv['id'])->ejecutar();
				$s = 'OK';
			} else {
				$s = 'NO_PERMISO';
			}
		} elseif( $p->que == 'categoria' ) {
			if( $this->_get_cate($p->id)
			and ($this->cate['id_usuario'] == $this->sesion->uid or es_admin() ) ) {
				// 1. elliminarlo de la base de datos
				$this->db()->del_by_id('diagnos_categorias',$this->cate['id']);
				// 2. quitarle el id a las preguntas dentro
				$this->db()->act('diagnos_preguntas',array('id_categoria' => 0),
					'id_categoria', $this->cate['id'] );
				$s = 'OK';
			} else {
				$s = 'NO_PERMISO';
			}
		} elseif( $p->que == 'preg' ) {
			if( $this->_get_preg($p->id) and $this->_get_cate($this->preg['id_categoria'])
			and ($this->cate['id_usuario'] == $this->sesion->uid or es_admin() ) ) {
				$this->db()->del_by_id('diagnos_preguntas',$this->preg['id']);
				// también borrar las respuestas y enunciados
				$this->db()->del_if('diagnos_respuestas','id_pregunta', $this->preg['id']);
				if( $this->preg['tipo'] == Diagno_Preg::ENUNCIADO )
					$this->db()->del_if('diagnos_enunciados','id_pregunta', $this->preg['id']);
				$s = 'OK';
			} else {
				$s = 'NO_PERMISO';
			}
		} elseif( $p->que == 'seccion' ) {
			if( $this->_get_usecc($p->id) and $this->_get_diagno($this->usecc['id_diagnostico'])
			and $this->_get_objeto($this->diagno['id_objeto']) and $this->_get_carpeta($this->objeto['id_carpeta'])
			and ($this->rol == 2 or $this->rol == 3 or es_admin()) ) {
				$this->db()->del_by_id('objetos_diagnos_secciones',$this->usecc['id']);
				$s = 'OK';
			} else {
				$s = 'NO_EXISTE';
			}
		} else {
			$s = 'NO_QUE';
		}
		$this->json->status = $s;
	}

	/**
	 * Muestra la lista de preguntas de una contestación,
	 * con posibilidad de retroalimentar y dar un calificación.
	 * Hay 2 modos posibles.
	 *   - Impresión. Se muestran todas las preguntas sin edición ni llenado.
	 *   - Normal. Se muestran categorizadas por sección y con posibilidad de llenar la retroalimentación.
	 */
	function contepregs() {
		$g =& $this->get;
		if( $this->_get_conte($g->id) and $this->_get_diagno($this->conte['id_diagnostico'])
		and $this->_get_objeto($this->diagno['id_objeto']) and $this->_get_carpeta($this->objeto['id_carpeta']) ) {
			$this->_get_secc(); 

			if( $g->sc == 'gen' ) {
				// sumar los puntos de todas las preguntas
				$calif = $this->db()->de('objetos_diagnos_contes_resp')
					->sel('AVG(valor) prom','MAX(valor) max','MIN(valor) min')
					->si('id_contes = ?', $this->conte['id'])
					->leer();
				$this->vista->llenar('diagnos/conte-retro.html',array(
					'titulo' => 'Retroalimentación General',
					'calif'  => $calif,
					'usuario'=> $this->_get_usuario($this->conte['id_usuario'])
				));
			} else {
				if( !$this->_get_usecc($g->sc) )
					$this->usecc = $this->secc[0];
				// las listas de preguntas se van a basar según el diagnóstico
				$qpregs = $this->db()->de('objetos_diagnos_preguntas')
					->si('id_diagnostico = ?', $this->diagno['id']);
				if( $g->modo != 'p' ) {
					$qpregs->si('id_seccion = ?', $this->usecc['id']);
				} else {
					$tt = strtotime($this->conte['fecha_terminado']) - strtotime($this->conte['fecha_inicio']);
					$hr = floor($tt/60/60);
					$tt -= ($hr*60*60);
					$mn = floor($tt/60);
					$tt -= ($mn*60);
					$this->usuario = $this->_get_usuario($this->conte['id_usuario']);
				}
				$this->pregs = $qpregs->leerTodos('id');
				// también las respuestas a las mismas
				$this->contepregs = array();
				$c_resps= $this->db()->de('objetos_diagnos_contes_resp')
					->si('id_contes = ?', $this->conte['id']);
				while( $rsp = $c_resps->leer() ) {
					if( isset($this->pregs[$rsp['id_pregunta']])
					and $this->pregs[$rsp['id_pregunta']]['tipo'] == Diagno_Preg::MULTIPLE ) {
						foreach( explode(',',$rsp['respuesta']) as $v )
							$rsp['resp'][$v] = $v;
					}
					$this->contepregs[$rsp['id_pregunta']] = $rsp;
				}
				// y obtener las respuestas para cada pregunta
				if( count($this->pregs) ) {
					foreach( $this->pregs as &$preg ) {
						if( $preg['tipo'] == Diagno_Preg::ENUNCIADO ) {
							$preg['enuns'] = $this->db()->de('objetos_diagnos_enuns enu')
								->sel('enu.*','cenun.texto')
								->ligarL('objetos_diagnos_contes_enuns cenun','enu.id','cenun.id_enun',array(
									array('cenun.id_usuario = ?', $this->conte['id_usuario']) ))
								->si('enu.id_pregunta = ?', $preg['id'])
								->leerTodos('id');
						} else {
							$preg['resp'] = $this->db()->de('objetos_diagnos_respuestas')
								->si('id_pregunta = ?', $preg['id'])
								->leerTodos();
						}
					}
				}
				$this->vista->llenar('diagnos/conte-pregs.html',array(
					'titulo' => 'Calificación y retroalimentación',
					'tiempo' => isset($hr) ? array('h'=>$hr,'m'=>$mn) : null
				));
			}
		} else {
			$this->vista->imprimir('No existe');
		}
	}
	
	/**
	 * Muestra pregunta por pregunta del diagnóstico,
	 * separado por sección a modo de asistente.
	 */
	function ejec() {
		$p =& $this->post;
		$g =& $this->get;
		$modo = (strlen($g->tk) > 10 and ($invi = $this->_get_invitado_por_tk($g->tk)))
			? 'inv' : 'reg';
		if( $this->_get_objeto($g->id) and $this->_get_carpeta($this->objeto['id_carpeta'])
		and $this->_get_obj_diagno($this->objeto['id']) and (
		($modo == 'reg' and $this->_get_conte_por_u($this->sesion->uid)) or
		($modo == 'inv' and $this->_get_conte_por_i($invi['id'])) ) ) {			
			$this->_get_secc();
			$tmfin = strtotime($this->conte['fecha_fin']);
			
			if( $this->conte['estado'] == 0) {
				$this->db()->act('objetos_diagnos_contes',array(
					'fecha_inicio'   => date('Y-m-d H:i:s'),
					'fecha_fin'      => date('Y-m-d H:i:s', time() + $this->diagno['duracion']),
					'estado'         => 1
				),'id',$this->conte['id']);
				$this->conte['estado'] = 1;
			}
			
			if( $this->conte['estado'] != 1 or ($tmfin and time() > $tmfin) ) {
				// volver al inicio, pues ya agotó
				header("Location: http://{$_SERVER['HTTP_HOST']}/diagnos/intro/?id={$this->objeto['id']}"
					.($modo == 'inv' ? '&tk='.$invi['clave'] : '') );
				exit;
			}
			if( !$this->_get_usecc($this->get->sc) )
				$this->_get_usecc($this->secc[0]['id']);
			$this->vista
				->load('media/css/diagno-pregs.css','css')
				->llenar('diagnos/diagno-ejec.html',array(
					'asecc'  => $this->_get_ant_secc(),
					'modo'   => $modo,
					'nsecc'  => $this->_get_sig_secc(),
					'titulo' => $this->objeto['nombre'],
					'seccid' => $this->usecc['id'],
					'diagno' => $this->diagno,
					'objeto' => $this->objeto,
					'conte'  => $this->conte
				));

		}
	}

	/**
	 * Muestra la estadística general del diagnóstico.
	 */
	function estad() {
		$g =& $this->get;
		$p =& $thid->post;
		if( $this->_get_obj_diagno($g->id)
		and $this->_get_objeto($this->diagno['id_objeto'])
		and $this->_get_carpeta($this->objeto['id_carpeta'])
		and ($this->rol == 1 or $this->rol == 2 or es_admin()) ) {
			$this->_get_secc();
			// obtener la lista de subcatálogos, para no tener que
			// estarlos consultando de la base de datos más veces
			$subcats = $this->db()->de('subcatalogos subcat')
				->sel('subcat.*')
				->si('subcat.id_catalogo = ?', $this->modulo->id_generacion)
				->si('conte.id_diagnostico = ?', $this->diagno['id'])
				->ligarI('usuarios u','subcat.id','u.id_subcatalogo')
				->ligarI('objetos_diagnos_contes conte','u.id','conte.id_usuario')
				->leerTodos('id');
			$subcats[] = array('id'=>0,'nombre'=>'Sin empresa');
			// contar los diagnósticos
			// TODO: optimizarlo para que no tenga que usar COUNT
			$dgval = $this->db()->de('objetos_diagnos_contes')
				->si('id_diagnostico = ?', $this->diagno['id'])
				->sel('COUNT(*) total','AVG(valor) prom')->leer();
			// obtener los promedios por subcatálogo
			$promcats = $this->db()->de('objetos_diagnos_contes odc')
				->sel('AVG(odc.valor) prom','MAX(odc.valor) max',
					'MIN(odc.valor) min','u.id_subcatalogo')
				->si('odc.id_diagnostico = ?', $this->diagno['id'])
				->ligarL('usuarios u','odc.id_usuario','u.id')
				->agrupar('u.id_subcatalogo')
				->leerTodos('id_subcatalogo');
			// calcular la hora
			$tt = $this->diagno['duracion'];
			$hr = floor($tt/60/60);
			$tt -= ($hr*60*60);
			$mn = floor($tt/60);
			$tt -= ($mn*60);
			$this->vista->llenar('diagnos/diagno-estad.html',array(
				'tiempo' => array('h'=>$hr,'m'=>$mn),
				'proms' => $promcats,
				'subcats' => $subcats,
				'titulo' => 'Estadística de Diagnóstico',
				'valores' => array(
					'ndiagnos' => $dgval['total'],
					'promedio' => round($dgval['prom'],2),
				)
			));
		} else {
			$this->vista->imprimir('No se encontró el objeto');
		}
	}

	function pregs() {
		$this->vista
			->load('media/css/diagno-pregs.css','css')
			->load('media/js/uploadify/uploadify.css','css')
			->load('media/js/uploadify/jquery.uploadify.js','js')
			->load('media/js/uploadify/swfobject.js','js')
			->llenar('diagnos/preg-lista.html',array(
				'titulo' => 'Preguntas',
				'cate'   => $this->_get_cate($this->get->cid)
			));
	}

	/**
	 * Muestra la lista de permisos especiales por usuario.
	 */
	function perms() {
		$g =& $this->get;
		if( $this->_get_objeto($g->id) and $this->_get_obj_diagno($this->objeto['id'])
		and $this->_get_carpeta($this->objeto['id_carpeta'])
		and ($this->rol == 2 or $this->rol == 3 or es_admin()) ) {
			$this->vista->llenar('diagnos/perms.html',array(
				'usuarios' => $this->db->de('usuarios u')
					->sel('u.*','op.permitir')
					->ligarI('roles r','u.id','r.id_usuario')
					->ligarL('objetos_permisos op','u.id','op.id_relacion',array(
						array('op.id_objeto = ?', $this->objeto['id']),
						array('op.tipo = ?', 'coordinar')
					))
					->si('r.id_curso = ?', $this->modulo->id)
					->leerTodos(),
				'titulo' => 'Permisos de cordinación'
			));
		} else {
			
		}
	}
	
	/**
	 * Muestra la interfaz de usuarios externos.
	 */
	function exter() {
		$g =& $this->get;
		if( $this->_get_obj_diagno($g->id) and $this->_get_objeto($this->diagno['id_objeto'])
		and $this->_get_carpeta($this->objeto['id_carpeta']) ) {
			// leer la lista de invitados del diagnóstico
			$this->invis = $this->db()->de('objetos_diagnos_invitados')
				->si('id_diagnostico = ?', $this->diagno['id'])
				->leerTodos();
			$this->vista->llenar('diagnos/invitados.html',array(
				'titulo' => 'Invitaciones al diagnóstico (externos)'
			));
		} else {
			$this->vista->imprimir('Sin permiso');
		}
	}
	
	/**
	 * Recibe la respuesta del cliente, de un diagnóstico
	 * y una pregunta específica.
	 */
	function resp() {
		$p =& $this->post;
		$modo = (strlen($p->tk) > 10 and ($invi = $this->_get_invitado_por_tk($p->tk)))
			? 'inv' : 'reg';
		if( $this->_get_diagno($p->dg) and $this->_get_preg($p->pg,'diag')
		and $this->diagno['id'] == $this->preg['id_diagnostico'] ) {
			// ver si existe la contestación
			if( $modo == 'reg' )
				$udiag = $this->_get_conte_por_u($this->sesion->uid);
			else
				$udiag = $this->_get_conte_por_i($invi['id']);
			if( !$udiag ) {

			} else {
				$conid = $udiag['id'];
			}
			// checar si existe la pregunta
			$upreg = $this->db()->un('objetos_diagnos_contes_resp')
				->si('id_contes=?', $conid)->si('id_pregunta=?',$this->preg['id'])
				->leer();
			if( !$upreg ) {
				// agregar la respuesta si no existe
				$pregid = $this->db()->ins('objetos_diagnos_contes_resp',array(
					'id_contes'    => $conid,
					'id_pregunta'  => $this->preg['id']
				));
			}
			
			// ya que aseguramos de que existe la respuesta
			// a la contestación, ahora vamos a validar la respuesta
			$respid = 0;
			$resptx = '';
			$resp = $this->_get_resp($p->respid);
			// determinar si la respuesta corresponde a la pregunta
			$respert = $resp and $resp['id_pregunta'] == $this->preg['id']; 
			switch( $this->preg['tipo'] ) {
				case Diagno_Preg::SELECCION:
					if( $respert )
						$respid = $p->respid;
					break;
					
				case Diagno_Preg::ABIERTA:
					$resptx = $p->resp;
					break;
					
				case Diagno_Preg::RANGO:
					if( $p->resp >= $this->preg['rango_de']
					and $p->resp <= $this->preg['rango_a'] ) {
						$resptx = $p->resp;
					}
					break;
					
				case Diagno_Preg::SINOPORQUE:
					if( $respert )
						$respid = $p->respid;
					$resptx = $p->resp;
					break;
				case Diagno_Preg::MULTIPLE:
					if( is_array($p->respid) ) {
						$p->respid = array_unique($p->respid);
						foreach( $p->respid as $idxr => $rid )
							if( !$rid > 0 or !($rsp = $this->_get_resp($rid))
							or $rsp['id_pregunta'] != $this->preg['id'] )
								unset($p->respid[$idxr]);
						if( count($p->respid) )
							$resptx = implode(',',$p->respid);
					}
					break;
					
				case Diagno_Preg::ENUNCIADO:
					if( is_array($p->enun) ) {
						foreach( $p->enun as $enid => $en ) {
							if( $prgenun = $this->_get_preg_enun($enid,$this->sesion->uid) ) {
								$this->db()->un('objetos_diagnos_contes_enuns')->actualizar()
									->poner('texto',$en)
									->si('id_enun=?',$enid)
									->si('id_usuario=?',$this->sesion->uid)
									->ejecutar();
									
							} else {
								$pregenun = array(
									'id_enun'    => $enid,
									'id_usuario' => $this->sesion->uid,
									'texto'      => $en );
								$this->db()->ins('objetos_diagnos_contes_enuns',$pregenun);
							}
						}
					}
					break;
				default:
					// no
					break;
			}
			// hacer la actualización
			$this->db()->un('objetos_diagnos_contes_resp')
				->actualizar()
				->poner('id_respuesta', $respid)
				->poner('respuesta', $resptx)
				->si('id_contes=?', $conid)
				->si('id_pregunta=?', $this->preg['id'])
				->ejecutar();
			$s = 'OK';
		} else {
			$s = 'NO_PERMISO';
		}
		$this->json->status = $s;
	}

	/**
	 * Cierra un diagnóstico.
	 */
	function fin() {
		$p =& $this->post;
		$modo = (strlen($p->tk) > 10 and ($invi = $this->_get_invitado_por_tk($p->tk)))
			? 'inv' : 'reg';
		if( $this->_get_objeto($p->id) and /*$this->_get_carpeta($this->objeto['id_carpeta'])
		and*/ $this->_get_obj_diagno($this->objeto['id']) and (
		($modo == 'reg' and $this->_get_conte_por_u($this->sesion->uid)) or
		($modo == 'inv' and $this->_get_conte_por_i($invi['id'])) )
		and $this->conte['estado'] == 1 ) {
			$this->db()->un('objetos_diagnos_contes')->actualizar()
				->poner('estado', 2)
				->poner('fecha_terminado', date('Y-m-d H:i:s'))
				->si('id = ?', $this->conte['id'])
				->ejecutar();
			$s = 'OK';
		} else {
			$s = 'NO_PERMISO';
		}
		$this->json->status = $s;
	}
	
	/**
	 * Reinicia alguno de los registro de algún
	 * aspecto del móduglo de diagnósticos.
	 */
	function reiniciar() {
		$p =& $this->post;
		if( $p->que == 'conte'  ) {
			if( $this->_get_conte($p->id) and $this->_get_diagno($this->conte['id_diagnostico'])
			and $this->_get_objeto($this->diagno['id_objeto']) and $this->_get_carpeta($this->objeto['id_carpeta']) ) {
				if( $this->rol == 2 || $this->rol == 3 || $this->sesion->usuario->posicion == 4 ) {
					$this->db()->act_u('objetos_diagnos_contes',array(
						'estado' => 0,
						'fecha_inicio' => '0000-00-00 00:00:00',
						'fecha_fin'    => '0000-00-00 00:00:00'
					),'id',$this->conte['id']);
					$s = 'OK';
				} else {
					$s = 'NO_PERMISO';
				}
			} else {
				$s = 'NO_EXISTE';
			}
		} else {
			$s = 'NO_QUE';
		}
		$this->json->status = $s;
	}
	
	/**
	 * Retroalimenta la contestación de un usuario
	 * de un diagnóstico y le agrega su valor.
	 */
	function retro() {
		$p =& $this->post;
		if( $this->_get_conte($p->id) and $this->_get_diagno($this->conte['id_diagnostico'])
		and $this->_get_objeto($this->diagno['id_objeto']) and $this->_get_carpeta($this->objeto['id_carpeta']) ) {
			if( $this->rol == 2 or $this->rol == 3 or es_admin() ) {
				if( $p->tipo == 'gen' ) {
					$cgen = $this->db()->un('objetos_diagnos_contes')->actualizar()
						->poner('retro', $p->retro)
						->si('id = ?', $this->conte['id']);
					if( is_numeric($p->valorcomp) )
						$cgen->poner('valor_complemento',$p->valorcomp);
					if( $this->diagno['metodo'] == 0 )
						$cgen->poner('valor', $p->calif);
					$cgen->ejecutar();
				} else {
					$vals = $p->valor;
					$retr = $p->retro;
					if( is_array($p->preg) ) {
						foreach( $p->preg as $pgid => $pggggg) {
							if( ($pg = $this->_get_preg($pgid,'diag'))
							and $pg['id_diagnostico'] == $this->conte['id_diagnostico'] ) {
								$val = (isset($vals[$pgid]) and $vals[$pgid] >= 0 and $vals[$pgid] <= $pg['valor_max'])
									? $vals[$pgid] : $pg['valor_max'];
								$this->db()->un('objetos_diagnos_contes_resp')->actualizar()
									->poner('retro',isset($retr[$pgid])?$retr[$pgid]:'')
									->poner('valor',$val)
									->si('id_pregunta = ?', $pgid)
									->si('id_contes = ?', $this->conte['id'])
									->ejecutar();
							}
						}
						// recalcular la calificación final
						if( $this->diagno['metodo'] != 0 ) {
							$vals = $this->db()->de('objetos_diagnos_contes_resp')
								->sel('AVG(valor) prom','MAX(valor) max','MIN(valor) min')
								->si('id_contes = ?', $this->conte['id'])
								->leer();
							$cvals = $this->db()->un('objetos_diagnos_contes')->actualizar()
								->si('id = ?', $this->conte['id']);
							if( $this->diagno['metodo'] == 1 ) {
								$cvals->poner('valor', $vals['prom']);
							} elseif( $this->diagno['metodo'] == 2 ) {
								$cvals->poner('valor', $vals['max']);
							} elseif( $this->diagno['metodo'] == 3 ) {
								$cvals->poner('valor', $vals['min']);
							}
							$cvals->ejecutar();
						}
					}
				}
				$s = 'OK';
			} else {
				$s = 'NO_PERMISO';
			}
		} else {
			$s = 'NO_PERMISO';
		}
		$this->json->status = $s;
	}

	/**
	 * Ordena un elemento.
	 */
	function ordenar() {
		$p =& $this->post;
		if( $p->que == 'seccion' ) {
			$direc = $p->direc == 'antes'? 'antes' : 'despues';
		 	// 1. Checar si existe el registro de la tabla de acuerdo al orden
		 	if( ($sec = $this->_get_usecc($p->id, true)) and ($secref = $this->_get_usecc($p->refid, true)) ) {
				$this->db()->act_u('objetos_diagnos_secciones',
					array('orden' => $secref['orden'] + ($direc == 'antes' ? 0 : 1)  ),
					'id',$sec['id']);
				$this->db()->actualizar('objetos_diagnos_secciones')
					->poner('orden = orden + 1')
					->si("id != ?", $sec['id'])
					->si('id_diagnostico = ?', $sec['id_diagnostico'])
					->si($direc == 'antes' ? 'orden >= ?' : 'orden > ?', $secref['orden'])
					->ejecutar();
				$s = 'OK';
			} else {
				$s = 'NO_EXISTE';
			}
		} else {
			$s = 'NO_QUE';
		}
		$this->json->status = $s;
	}
	
	function obtener() {
		$p =& $this->post;
		if($this->post->que=='pregs'){
			$cpregs = $this->db()->de('diagnos_preguntas dp')
				->si('dp.id_usuario = ?', $this->sesion->uid)
				->si('dp.id_categoria = ?', $p->cateid);
			//if( is_numeric($this->post->nodg) && $this->post->nodg > 0 )
				// excluir las preguntas que ya estén en un diagnóstico
				//$cpregs->ligarL('objetos_diagnos_preguntas odp','dp.id','odp.id_preg_orig')
					//->si("odp.id_diagnostico != '{$this->post->nodg}'");
					//;
			$pregs = $cpregs->leerTodos();
			if( count($pregs) ) {
				foreach( $pregs as &$preg ) {
					if( $preg['tipo'] == Diagno_Preg::ENUNCIADO ) {
						$preg['enuns'] = $this->db()->de('diagnos_enunciados')
							->si('id_pregunta = ?', $preg['id'])
							->leerTodos();
					} else {
						$preg['resp'] = $this->db()->de('diagnos_respuestas')
							->si('id_pregunta = ?', $preg['id'])
							->leerTodos();
					}
				}
			}
			$this->json->pregs = $pregs;
		} elseif( $this->post->que == 'diagpregs' ) { //preguntas de diagnóstico
			$this->_get_diagno($p->diagid);
			$modo = (strlen($p->tk) > 10 and ($invi = $this->_get_invitado_por_tk($p->tk)))
				? 'inv' : 'reg';
			$hay_conte = ($modo == 'reg' and $this->_get_conte_por_u($this->sesion->uid)) or
				($modo == 'inv' and $this->_get_conte_por_i($invi['id']));
			$pregs = array();
			$cprg = $this->db()->de('objetos_diagnos_preguntas')
				->si('id_diagnostico = ?', $p->diagid)
				->si('id_seccion = ?', $p->seccid);
			while( $preg = $cprg->leer() ) {
				if( $preg['tipo'] == Diagno_Preg::ENUNCIADO ) {
					$cenuns = $this->db()->de('objetos_diagnos_enuns enu')
						->sel('enu.*','cenun.texto')
						->ligarL('objetos_diagnos_contes_enuns cenun','enu.id','cenun.id_enun',array(
							array('cenun.id_usuario = ?', $this->conte['id_usuario']) ))
						->si('enu.id_pregunta = ?', $preg['id']);
					$preg['enuns'] = $cenuns->leerTodos('id');
				} else {
					$preg['resp'] = $this->db()->de('objetos_diagnos_respuestas')
						->si('id_pregunta = ?', $preg['id'])
						->leerTodos();
				}
				$pregs[] = $preg; 
			}
			// leer lo respondido por el usuario
			if( $this->post->respondidas == 1 ) {
				if( $this->diagno and $hay_conte ) {
					$this->json->respue = $this->db()->de('objetos_diagnos_contes_resp')
						->si('id_contes = ?', $this->conte['id'])
						->leerTodos('id_pregunta');
					// leer los enunciados
					/*foreach( $pregs as &$prg ) {
						if($prg['tipo'] == Diagno_Preg::ENUNCIADO and is_array($prg['enuns']) ) {
							foreach( $prg['enuns'] as &$enn )
								$enn['texto'] = $this->db()->
						}
					}*/
				}
			}
			if( $modo == 'reg' )
				$this->_get_conte_por_u($this->sesion->uid);
			else
				$this->_get_conte_por_i($invi['id']);
			$this->json->pregs = $pregs;
		}
	}
	
	/**
	 * Muestra las preguntas listas para catalogoar
	 * dentro de un diagnóstico.
	 */
	function capreg() {
		$p =& $this->post;
		$g =& $this->get;
		if( $this->_get_objeto($g->id) and $this->_get_obj_diagno($this->objeto['id']) ) {
			$this->_get_secc();
			if( !$this->_get_usecc($this->get->sc) )
				$this->_get_usecc($this->secc[0]['id']);
			$this->vista
				->load('media/css/diagno-pregs.css','css')
				->llenar('diagnos/diagno-pregs.html',array(
					'titulo' => 'Agregar preguntas a Diagnóstico',
					'seccid' => $this->usecc['id'],
					'diagno' => $this->diagno,
					'objeto' => $this->objeto,
					'cate'   => $this->_get_cate($this->usecc['id']),
					'cates'  => $this->_get_cates()
				));
		} else {
			 $s = 'NO_OBJETO';
		}
	}
	
	/**
	 * Vista previa del diagnóstico.
	 */
	function ver() {
		$p =& $this->post;
		$g =& $this->get;
		if( $this->_get_objeto($g->id) and $this->_get_carpeta($this->objeto['id_carpeta'])
		and $this->_get_obj_diagno($this->objeto['id']) ) {
			$this->_get_secc();
			if( !$this->_get_usecc($this->get->sc) )
				$this->_get_usecc($this->secc[0]['id']);
			$this->vista
				->load('media/css/diagno-pregs.css','css')
				->llenar('diagnos/diagno-prev.html',array(
					'titulo' => $this->objeto['nombre'],
					'seccid' => $this->usecc['id'],
					'diagno' => $this->diagno,
					'objeto' => $this->objeto
				));
		}
	}
	
	/**
	 * Agrega o edita un diagnóstico o una categoría.
	 * La variable "que" llega por POST y define el tipo
	 * de operación que se realizará, este puede ser:
	 *   - categoria. Categoría de preguntas de diagnóstico.
	 *   - pregunta. Pregunta de categoría.
	 *   - diagno. Un diagnóstico.
	 *   - invi. Invitado externo a un diagnóstico.
	 *   - diagperms. Permisos de coordinación.
	 * Si no existe "que" se responde con NO_QUE.
	 */
	function guardar() {
		$p =& $this->post;
		$que = $p->que;
		try {
			if( $que == 'categoria' ) {
				$ca = new Diagno_Cat();
				$ca->nombre = $p->nombre;
				if( $p->id ) {
					$ca->id = $p->id;
					$ca->guardar();
				} else {
					$ca->uid = $this->sesion->uid;
					$this->json->uid = $ca->insertar();
				}
				$this->json->cate = array(
					'id'     => $ca->id,
					'nombre' => $ca->nombre );
				$s = 'OK';
				
			} elseif( $que == 'pregunta' ) {
				$cp = new Diagno_Preg();
				$cp->pregunta = $p->pregunta;
				$cp->ayuda = $p->ayuda;
				$cp->porque = $p->porque;
				if($p->id) {
					$cp->id = $p->id;
				} else {
					$cp->uid = $this->sesion->uid;
					$cp->tipo = $p->tipo;
					$cp->id_categoria = $p->cateid;
					if( file_exists('recursos/diagnos/img/'.$p->imagen) )
						$cp->imagen = $p->imagen;
				}
				// Agregar los datos de rango
				if( $p->tipo == Diagno_Preg::RANGO ) {
					$rangode = round($p->rangode,0);
					$rangohasta = round($p->rangohasta,0);
					if( $rangode >= $rangohasta )
						throw new Exception('RANGO_NO_VALIDO');
					$cp->rango_de    = $rangode;
					$cp->rango_a = $rangohasta;
					$cp->rango_de_nombre = $p->rango_de_nombre;
					$cp->rango_a_nombre = $p->rango_a_nombre;
				}
				$cp->guardar();
				// agregar las preguntas
				if( $p->tipo == Diagno_Preg::MULTIPLE || $p->tipo == Diagno_Preg::SELECCION ) {
					if( is_array($p->resp) ) {
						foreach($p->resp as $iresp => $resp) {
							$idresp = $cp->add_resp($resp);
							if( $cp->tipo == Diagno_Preg::SELECCION and $iresp == $p->respcor ) {
								$cp->set_correcta($idresp);
							} elseif( $cp->tipo == Diagno_Preg::MULTIPLE and isset($p->respcor[$iresp]) ) {
								$cp->set_correcta($idresp);
							}
						}	
					}
				} elseif( $p->tipo == Diagno_Preg::SINOPORQUE ) {
					$idsi = $cp->add_resp('Si');
					$idno = $cp->add_resp('No');
					$cp->set_correcta( $p->sino == 'si' ? $idsi : $idno );
				}
				
				// también los enunciados, en caso de aplicar
				if( $p->tipo == Diagno_Preg::ENUNCIADO ) {
					if( is_array($p->antes) ) {
						foreach( $p->antes as $atid => $at ) {
							if( trim($at) ) {
								$dp = isset($p->despues[$atid]) ? $p->despues[$atid] : '';
								$this->db()->ins('diagnos_enunciados',array(
									'id_pregunta' => $cp->id,
									'antes'   => $at,
									'despues' => $dp
								));
							}
						}
					}
				}
				$this->json->preg = array(
					'id'         => $cp->id,
					'pregunta'   => $cp->pregunta,
					'id_correcta'=> $cp->id_correcta,
					'ayuda'      => $cp->ayuda,
					'tipo'       => $cp->tipo,
					'resp'       => $cp->get_resp());
				$s = 'OK';
			} elseif( $que == 'diagno' ) {
				$o = new Objeto(Objeto::DIAGNOSTICO);
				if( $p->id and is_numeric($p->id) ) {
					$o->id = $p->id;
					$es_nuevo = false;
				} else {
					$es_nuevo = true;
				}
				$o->nombre = $p->nombre;
				$o->descripcion = $p->instrucciones;
				$o->carpeta_id = $p->cid;
				$o->fecha_inicio = $p->fecha_inicio;
				$o->fecha_fin = $p->fecha_fin;
				$p->secc = is_array($p->secc)
					? array_filter($p->secc,'trim')
					: array();
				if( $es_nuevo and !count($p->secc) )
					throw new Exception('NO_SECC');
				if( $p->metodo < 0 or $p->metodo > 3 )
					throw new Exception('NO_METODO');
				if( $o->guardar() ) {
					// si se guardó bien el objeto, ahora guardamos los
					// datos específicos del diagnóstico
					$secc = $p->secc;
					$dur  = $p->sinlim ? 0 : $p->getEntero('duracion');
					$vest = $p->verest ? 1 : 0;
					if( $es_nuevo ) {
						$diagid = $this->db()->ins('objetos_diagnos',array(
							'id_objeto' => $o->id,
							'duracion'  => $dur*60, // a segundos
							'ver_estad' => $vest,
							'etiqueta_complemento' => $p->etiqueta_complemento,
							'metodo'    => $p->metodo,
							'sin_limite'=> $p->sinlim ? 1 : 0 ));
					} else {
						$this->db()->act_u('objetos_diagnos',array(
							'duracion'  => $dur*60,
							'ver_estad' => $vest,
							'metodo'    => $p->metodo,
							'etiqueta_complemento' => $p->etiqueta_complemento,
							'sin_limite'=> $p->sinlim ? 1 : 0
						),'id_objeto',$o->id);
					}
					if( $es_nuevo and is_array($secc) ) {
						// por último agregar las secciones
						$isecc = 0;
						foreach($secc as $scc) {
							$this->db()->insertar('objetos_diagnos_secciones',array(
								'id_diagnostico' => $diagid,
								'nombre'         => $scc,
								'orden'          => ++$isecc
							));
						}
					}
					$s = 'OK';
				}	
			} elseif( $que == 'invi' ) {
				if( !$this->_get_diagno($p->dg) or !$this->_get_objeto($this->diagno['id_objeto'])
				or  !$this->_get_carpeta($this->objeto['id_carpeta']) )
					throw new Exception('NO_DIAGNOSTICO');
				if( $this->rol != 2 and $this->rol != 3 and $this->sesion->usuario->posicion != 4 )
					throw new Exception('NO_PERMISO');
				if( !trim($p->nombre) )
					throw new Exception('NO_NOMBRE');
				$info = array(
					'id_diagnostico' => $this->diagno['id'],
					'clave' => md5(date('YmdHis') . rand(1111,9999)), // <-- clave única
					'nombre' => $p->nombre,
					'correo' => $p->correo );
				$invid = $this->db()->insertar('objetos_diagnos_invitados', $info);
				$info['id'] = $invid;
				$this->json->invi = $info;
				$s = 'OK';
			} elseif( $que == 'seccion' ) {
				// TODO: No se debe permitir que cualquier agregue.
				if( $this->_get_diagno($p->dg) and $p->nombre ) {
					$secid = $this->db()->ins('objetos_diagnos_secciones',array(
						'id_diagnostico' => $this->diagno['id'],
						'nombre' => $p->nombre
					));
					// actualizar el órden
					$this->db()->act_u('objetos_diagnos_secciones',
						array('orden' => $secid),'id',$secid);
					$s = 'OK';
				} else {
					$s = 'NO_EXISTE';
				}
			} elseif( $que == 'diagperms' ) {
				if( $this->_get_diagno($p->dg) and $this->_get_objeto($this->diagno['id_objeto'])
				and $this->_get_carpeta($this->objeto['id_carpeta'])
				and ($this->rol == 2 or $this->rol == 3 or es_admin()) ) {
					$usuarios = $p->usuario;
					if( is_array($usuarios) ) {
						// tomar lista de permisos de cordinación
						$ucoor = $this->db->de('objetos_permisos op')
							->sel('op.id_relacion id')
							->si('op.tipo = ?', 'coordinar')
							->si('op.id_objeto = ?', $this->objeto['id'])
							->leerTodos('id','id');
						
						// Buscar los usuarios que se van a agregar
						// TODO: no se deben de guardar usuarios que no existen
						foreach( $usuarios as $uid => $us )
							if( !isset($ucoor[$uid]) and is_numeric($uid) )
								$this->db->ins('objetos_permisos',array(
									'tipo'        => 'coordinar',
									'id_objeto'   => $this->objeto['id'],
									'id_relacion' => $uid,
									'permitir'    => 1	));
									
						// Y las que se van a eliminar
						foreach( $ucoor as $uid => $us )
							if( !isset($usuarios[$uid]) )
								$this->db->del_if('objetos_permisos',array(
									'id_objeto'   => $this->objeto['id'],
									'id_relacion' => $uid,
									'tipo'        => 'coordinar'	));

					}
					$s = 'OK';
				} else {
					$s = 'NO_EXISTE';
				}
			} else { $s ='NO_QUE'; }
		} catch (Exception $ex) {
			$s = $ex->getMessage();
		}
		$this->json->status = $s;
	}

	/**
	 * Carga una imagen con extensión jpeg, png o gif.
	 * Después devuelve el nombre de la misma.
	 */
	function cargarimg() {
		$p =& $this->post;
		$f =& $_FILES;
		if( !is_dir('recursos/diagnos') )
			mkdir('recursos/diagnos');
		if( !is_dir('recursos/diagnos/img') )
			mkdir('recursos/diagnos/img');
		if( isset($f['archivo']) and $f['archivo']['size'] ) {
			$tp = exif_imagetype($f['archivo']['tmp_name']);
			if( $tp == IMAGETYPE_JPEG or $tp == IMAGETYPE_GIF or $tp == IMAGETYPE_PNG ) {
				// generar un nombre aleatorio
				$xt = $tp == IMAGETYPE_JPEG ? '.jpg' : (IMAGETYPE_PNG ? '.png' : '.gif');
				$fn = md5(date('YmdHis').rand(11111,99999)).$xt;
				copy($f['archivo']['tmp_name'],'recursos/diagnos/img/'.$fn);
				$s = 'OK';
				$this->json->archivo = $fn;
			} else {
				$s = 'NO_FORMATO';
			}
		}
		$this->json->status = $s;
	}

	/**
	 * Envía mensajes u otras cosas relacionadas con
	 * los diagnósticos. Puede ser:
	 *   - Invitaciones a personas externas.
	 */
	function enviar() {
		$p =& $this->post;
		if( $p->que == 'invi' ) {
			if( ($invi = $this->_get_invitado($p->id)) and $this->_get_diagno($invi['id_diagnostico']) ) {
				if( preg_match('/@/',$invi['correo']) ) {
					$urldiag = 'http://'.$_SERVER['HTTP_HOST'].'/diagnos/intro/?id='.$this->diagno['id_objeto'].'&tk='.$invi['clave'];
					$ntf = new Notificacion();
					$ntf->setAsunto('Invitación a Diagnóstico');
					$ntf->setCorreo($invi['correo']);
					$ntf->setCuerpo(
						'Has sido invitado a realizar un diagnóstico en plataforma eDinamics.<br>'.
						'Sigue el enlace siguiente para iniciarlo<br>'.
						'<a href="'.$urldiag.'">'.$urldiag.'</a><br>'
					);
					$ntf->guardar();
					$s = 'OK';
				} else {
					$s = 'NO_CORREO';
				}
			} else {
				$s = 'NO_INVITADO';
			}
		} else {
			$s = 'NO_QUE';
		}
		$this->json->status = $s;
	}

	/**
	 * Manda a incluir una pregunta a un diagnóstico.
	 */
	function incluir() {
		$g =& $this->get;
		$p =& $this->post;
		//var_dump($this->_get_diagno($p->dg));
		if( $this->_get_diagno($p->dg) and $this->_get_preg($p->pr)
		and is_numeric($p->sc) ) { // TODO: mejorar la validación de sección
			// 1. reinsertar la info de la pregunta en la nueva tabla
			$pid = $this->db()->insertar('objetos_diagnos_preguntas',array(
				'id_preg_orig'   => $this->preg['id'],
				'id_diagnostico' => $this->diagno['id'],
				'id_seccion'     => $p->sc,
				'pregunta'       => $this->preg['pregunta'],
				'ayuda'          => $this->preg['ayuda'],
				'valor_max'      => $p->valor,
				// 'respuesta'   => '' TODO: no va, porque no hay nada qué llenar
				'porque'         => $this->preg['porque'],
				'tipo'           => $this->preg['tipo'],
				'imagen'         => $this->preg['imagen'],
				'rango_de'       => $this->preg['rango_de'],
				'rango_a'        => $this->preg['rango_a'],
				'rango_de_nombre'=> $this->preg['rango_de_nombre'],
				'rango_a_nombre' => $this->preg['rango_a_nombre']
			));
			// 2. obtener la lista de respuestas y hacer lo mismo
			$corid = 0; // respuesta correcta
			$resps = $this->_get_resps($this->preg['id']);
			foreach( $resps as $rsp ) {
				$nrespid = $this->db()->insertar('objetos_diagnos_respuestas',array(
					'id_resp_orig' => $rsp['id'],
					'id_pregunta'  => $pid,
					'respuesta'    => $rsp['respuesta'],
					'es_correcta'  => $rsp['es_correcta'] ));
				if( $rsp['id'] == $this->preg['id_correcta'] )
					$corid = $nrespid;
			}
			// 3. Después los enunciados
			$enuns = $this->db()->de('diagnos_enunciados')
				->si('id_pregunta = ?', $this->preg['id'])->leerTodos();
			foreach( $enuns as $enu ) {
				$enunid = $this->db()->ins('objetos_diagnos_enuns',array(
					'id_enun_orig' => $enu['id'],
					'id_pregunta' => $pid,
					'antes' => $enu['antes'],
					'despues' => $enu['despues']
				));
			}
			// 4. actualizar la respuesta correcta
			$this->db()->act_u('objetos_diagnos_preguntas',
				array('id_correcta'=>$corid), 'id', $pid);
				
			$s = 'OK';
		} else { $s = 'NO_PERMISO'; }
		$this->json->status = $s;
	}

	/**
	 * Desvincula una pregunta de diagn
	 */
	function desincluir() {
		$p =& $this->post;
		if( $this->_get_diagno($p->dg) and $this->_get_preg($p->pg,'diag') ) {
			$this->db()->un('objetos_diagnos_preguntas')->eliminar()
				->si('id = ?', $this->preg['id'])
				->si('id_diagnostico = ?', $this->diagno['id'])
				->ejecutar();
			$s = 'OK';
		} else {
			$s = 'NO_PERMISO';
		}
		$this->json->status = $s;
	}

	/**
	 * Cambia los valores de cada pregunta.
	 */
	function valorar() {
		$p =& $this->post;
		if( $this->_get_diagno($p->dg) and $this->_get_objeto($this->diagno['id_objeto'])
		and $this->_get_carpeta($this->objeto['id_carpeta'] )
		and ($this->rol == 2 or $this->rol == 3 or es_admin() )  ) {
			if( is_array($p->valor) ) {
				foreach( $p->valor as $pid => $v ) {
					$this->db()->act_u('objetos_diagnos_preguntas',
						array('valor_max' => $v),'id',$pid);
				}
			}
			$s = 'OK';
		} else {
			$s = 'NO_PERMISO';
		}
		$this->json->status = $s;
	}

	/**
	 * Valida si la pregunta es
	 */
	function _existe_conte_preg($cid, $pid) {
		return $this->db()->existe('objetos_diagnos_contes_resp',array(
			'id_contes' => $cid,
			'id_pregunta' => $pid
		));
	}
	
	/**
	 * Devuelve la información del objeto tipo Diagnóstico.
	 */
	function &_get_objeto($id) {
		if( !$this->objeto ) {
			$this->objeto = $this->db()->un('objetos')
				->si('id = ?', $id)->si('tipo = ?', Objeto::DIAGNOSTICO)
				->leer();
		}
		return $this->objeto;
	}
	
	/**
	 * Devuelve la información del objeto tipo Diagnóstico.
	 */
	function &_get_obj_diagno($id) {
		if( !$this->diagno ) {
			$this->diagno = $this->db()->un('objetos_diagnos')
				->si('id_objeto = ?', $id)
				->leer();
		}
		return $this->diagno;
	}

	function &_get_diagno($id) {
		if( !$this->diagno ) {
			$this->diagno = $this->db()->un('objetos_diagnos')
				->si('id = ?', $id)
				->leer();
		}
		return $this->diagno;
	}
	
	/**
	 * Devuelve la lista de secciones
	 * que pertenecen al diagnóstico.
	 */
	function &_get_secc() {
		if( !$this->secc ) {
			$this->secc = $this->db()->de('objetos_diagnos_secciones')
				->si('id_diagnostico = ?', $this->diagno['id'])
				->ordenar('orden asc')
				->leerTodos();
		}
		return $this->secc;
	}
	
	/**
	 * Devuelve la información de una sección.
	 * @param $id Identificador de la sección en la db.
	 */
	function &_get_usecc($id, $forzar = false) {
		if( !$this->usecc or $forzar ) {
			$this->usecc = $this->db()->un('objetos_diagnos_secciones')
				->si('id = ?', $id)
				->leer();
		}
		return $this->usecc;
	}
	
	/**
	 * Devuelve la info de la siguiente sección.
	 * Esta función asume que ya leiste previamente la sección actual.
	 * O bien haber llamado a la función $this->_get_usecc.
	 */
	function &_get_sig_secc() {
		if( !$this->nsecc ) {
			$this->nsecc = $this->db()->un('objetos_diagnos_secciones')
				->si('orden > ?',$this->usecc['orden'])
				->si('id_diagnostico = ?', $this->diagno['id'])
				->ordenar('orden asc')
				->leer();
		}
		return $this->nsecc;
	}
	
	/**
	 * Devuelve la info de la sección anterior a la actual.
	 * Esta función asume que ya leiste previamente la sección actual.
	 * O bien haber llamado a la función $this->_get_usecc.
	 */
	function &_get_ant_secc() {
		if( !$this->asecc ) {
			$this->asecc = $this->db()->un('objetos_diagnos_secciones')
				->si('orden < ?',$this->usecc['orden'])
				->si('id_diagnostico = ?', $this->diagno['id'])
				->ordenar('orden asc')
				->leer();
		}
		return $this->asecc;
	}
	
	/**
	 * Devuelve la info de una categoría de diagnóstico.
	 */
	function &_get_cate($id) {
		if( !$this->cate )
			$this->cate = $this->db()->un('diagnos_categorias')
				->si('id=?',$id)->leer();
		return $this->cate;
	}
	
	/**
	 * Devuelve la lista de categorías del usuario.
	 */
	function &_get_cates() {
		if( !$this->cates ) {
			$this->cates = $this->db()->de('diagnos_categorias')
				->si('id_usuario=?', $this->sesion->uid)
				->leerTodos('id');
		}
		return $this->cates;
	}
	
	/**
	 * Devuelve la información de una pregunta.
	 */
	function &_get_preg($id, $orig = 'lib') {
		//if( !$this->preg ) {
		$this->preg = $this->db()->un(
			$orig=='diag' ? 'objetos_diagnos_preguntas' : 'diagnos_preguntas')
			->si('id=?',$id)->leer();
		//}
		return $this->preg;
	}
	
	/**
	 * Devuelve la lista de respuestas de una pregunta.
	 */
	function _get_resps($id) {
		return $this->db()->de('diagnos_respuestas')
			->si('id_pregunta=?',$id)->leerTodos();
	}
	
	/**
	 * Teniendo ya leido el diagnóstico con la función
	 * $this->_get_diagno, con este obtenemos la respectiva
	 * contestación del usuario especificado.
	 */
	function &_get_conte_por_u($uid) {
		if( $this->diagno and !$this->conte ) {
			$this->conte = $this->db()->un('objetos_diagnos_contes')
				->si('id_usuario = ?', $uid)
				->si('id_diagnostico = ?', $this->diagno['id'])
				->leer();
			// agregar el tiempo restante
			if( $this->conte ) {
				$fin = strtotime($this->conte['fecha_fin']);
				$this->conte['tiempo_restante'] = $fin > 0
					? $fin - time()
					: $this->diagno['duracion'];
			} 
		}
		return $this->conte;
	}
	
	function &_get_conte_por_i($uid) {
		if( $this->diagno and !$this->conte ) {
			$this->conte = $this->db()->un('objetos_diagnos_contes')
				->si('id_invitado = ?', $uid)
				->si('id_diagnostico = ?', $this->diagno['id'])
				->leer();
			// agregar el tiempo restante
			if( $this->conte ) {
				$fin = strtotime($this->conte['fecha_fin']);
				$this->conte['tiempo_restante'] = $fin > 0
					? $fin - time()
					: $this->diagno['duracion'];
			} 
		}
		return $this->conte;
	}
	
	/**
	 * Devuelve los enunciados de una pregunta contesada.
	 */
	function _get_preg_enun($eid,$uid) {
		return $this->db()->un('objetos_diagnos_contes_enuns')
			->si('id_enun=?',$eid)
			->si('id_usuario=?',$uid)->leer();
	}
	/**
	 * Devuelve la información de una contestación
	 * de acuerdo al id de la misma especificado.
	 */
	function &_get_conte($id) {
		if( !$this->conte ) {
			$this->conte = $this->db()->un('objetos_diagnos_contes')
				->si('id = ?', $id)->leer();
		}
		return $this->conte;
	}
	
	/**
	 * Devuelve la lista de contestaciones de un diagnóstico
	 * especificado. Devuelve todo junto.
	 */
	function &_get_contes($did) {
		if( !$this->contes ) {
			$this->contes = $this->db()->de('objetos_diagnos_contes odc')
				->sel('odc.*','u.nombre unombre','u.id uid')
				->si('odc.id_diagnostico = ?', $did)
				->ligarL('usuarios u','odc.id_usuario','u.id')
				->ordenar('u.nombre asc')
				->leerTodos();
		}
		return $this->contes;
	}
	
	/**
	 * Devuelve la información sobre una respuesta
	 * de un diagnóstico.
	 */
	function _get_resp($id,$orig='diagno') {
		return $this->db()->un($orig=='diagno'?'objetos_diagnos_respuestas':'diagnos_respuestas')
			->si('id=?',$id)->leer();
	}
	
	private function _get_carpeta($id) {
		// Primero obtenemos la información de la clase
		$this->carpeta = $this->db()-> un('clases')
			->sel('id_clase id', 'clases.*')
			->si('id_clase = ?', $id)->si('eliminada = ?', 0)
			->leerModelo();
		$clase = $this->carpeta;
		if( $clase )
			while( !$clase->id_curso && $clase->id_clase_padre > 0 )
				$clase = $this->db()->un('clases')
					-> sel('id_clase id', 'clases.*')
					-> si('id_clase = ?', $clase->id_clase_padre)
					-> si('eliminada = ?', 0)
					-> leerModelo();
		if( !isset($this->modulo) && $clase )
			$this->_get_modulo($clase->id_curso);

		return $this->carpeta;
	}
	
	private function _get_modulo($id) {
		$this->modulo = $this->db()->un('cursos')
			->sel('id_curso id','cursos.*',"CONCAT(clave,' ',nombre) as nombre")
			->si('id_curso = ?', $id)->si('estado = 1', null)
			->leerModelo();
		if( $this->modulo )
			$this->_get_mod_rol($this->modulo->id);
		return $this->modulo;
	}
	
	/**
	 * Devuelve el rol del usuario actual (en sesión),
	 * con relación al módulo especificado.
	 */
	function &_get_mod_rol($id) {
		$rrol = $this->db()->un('roles r')->sel('r.rol')
			->si('r.id_usuario = ?', $this->sesion->uid)
			->si('r.id_curso = ?', $id )
			->leer();
		$this->rol = $rrol ? $rrol['rol'] : 0;
		return $this->rol;
	}
	
	/**
	 * Devuelve la info de un usuario.
	 */
	function _get_usuario($id) {
		return $this->db()->un('usuarios')
			->si('id=?',$id)->leer();
	}
	
	/**
	 * Devuelve la información de un invitado.
	 */
	function _get_invitado($id) {
		return $this->db()->un('objetos_diagnos_invitados')
			->si('id=?',$id)->leer();
	}
	
	/**
	 * Devuelve la información de un invitado
	 * especificando el token único.
	 */
	function _get_invitado_por_tk($tk) {
		return $this->db()->un('objetos_diagnos_invitados')
			->si('clave = ?', $tk)->leer();
	}
	
	/**
	 * Evalúa si el usuario en sesión tiene permiso
	 * de coordinación.
	 */
	function _perm_coord($oid) {
		if( $this->sesion->uid ) {
			return $this->db->un('objetos_permisos')
				->si('id_objeto = ?',   $oid)
				->si('id_relacion = ?', $this->sesion->uid)
				->si('tipo = ?',        'coordinar')->leer() != null;
		}
		return false;
	}
	
	/**
	 * Genera el HTML de las pestañas de sección
	 */
	function _html_pest_secc($modo='capreg') {
		$pest = array();
		$i = 0;
		if( is_array($this->secc) ) {
			foreach( $this->secc as $sc )
				$pest[$sc['id']] = array(
					'texto' => '<strong>'. (++$i) . '.</strong> ' . $sc['nombre'],
					'link'  => 'diagnos/'.$modo.'/?id='.
					($modo == 'contepregs' ? $this->conte['id'] : $this->objeto['id'])
					.'&sc='.$sc['id'].(strlen($this->get->tk) > 10 ? '&tk='.$this->get->tk : '')
				);
				if( $modo == 'contepregs' ) {
					$pest['gen'] = array(
						'texto' => 'Retroalimentación General',
						'link'  => 'diagnos/'.$modo.'/?id='.$this->conte['id'].'&sc=gen'
					);
				}
		}
		return html_pestanas($pest,$this->get->sc);
	}
}
